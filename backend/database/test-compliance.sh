#!/usr/bin/env bash
# §16 security & compliance: tenant isolation, RBAC, tokens, and the data
# subject rights the regulations give the patient rather than the operator.
set -uo pipefail
BASE="${BASE:-http://127.0.0.1:8000/api/v1}"
MYSQL="${MYSQL:-/c/xampp/mysql/bin/mysql.exe}"
DB="${DB:-mediflow}"
PASS=0; FAIL=0
# Sign-ins are rate limited per IP, and a suite that runs straight after
# another starts with a bucket somebody else filled. Clearing it first means a
# 429 in this suite is this suite's fault. (test-security.sh is the exception:
# it wants the limiter, and clears the bucket right before measuring it.)
reset_limits() { [ -x "$MYSQL" ] && "$MYSQL" -u root "$DB" -e "TRUNCATE TABLE rate_limits;" 2>/dev/null; }
reset_limits
step() { printf '\n\033[1m%s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m  %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m  %s  %s\n' "$1" "${2:-}"; }
want() { if [ "$2" = "$3" ]; then ok "$1 -> $2"; else bad "$1" "got [$2] want [$3]"; fi; }
sql()  { "$MYSQL" -u root "$DB" -N -e "$1"; }
tok()  { curl -s -X POST "$BASE/auth/login" -H 'Content-Type: application/json' \
           -d "{\"email\":\"$1\",\"password\":\"Password123\"}"; }

PLOGIN=$(tok patient@demo.test)
PTOK=$(echo "$PLOGIN" | grep -o '"access_token":"[^"]*"' | head -1 | cut -d'"' -f4)
PREF=$(echo "$PLOGIN" | grep -o '"refresh_token":"[^"]*"' | head -1 | cut -d'"' -f4)
OTOK=$(tok owner@clinic.test | grep -o '"access_token":"[^"]*"' | head -1 | cut -d'"' -f4)
RTOK=$(tok reception@clinic.test | grep -o '"access_token":"[^"]*"' | head -1 | cut -d'"' -f4)
[ -z "$PTOK" ] && { echo "cannot sign in"; exit 1; }
P=(-H "Authorization: Bearer $PTOK" -H 'Content-Type: application/json')
O=(-H "Authorization: Bearer $OTOK" -H 'X-Organization-Id: 1' -H 'Content-Type: application/json')
R=(-H "Authorization: Bearer $RTOK" -H 'X-Organization-Id: 1' -H 'Content-Type: application/json')

step "1. Token-based auth, with a refresh that rotates"
[ -n "$PTOK" ] && ok "an access token is issued" || bad "no access token"
[ -n "$PREF" ] && ok "and a refresh token" || bad "no refresh token"
NEW=$(curl -s -X POST "$BASE/auth/refresh" -H 'Content-Type: application/json' \
      -d "{\"refresh_token\":\"$PREF\"}")
echo "$NEW" | grep -q '"access_token"' && ok "refresh returns a fresh access token" || bad "refresh failed"
NEWREF=$(echo "$NEW" | grep -o '"refresh_token":"[^"]*"' | head -1 | cut -d'"' -f4)
if [ -n "$NEWREF" ] && [ "$NEWREF" != "$PREF" ]; then ok "the refresh token rotated"
else bad "the refresh token was reused" ; fi
C=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/auth/refresh" \
      -H 'Content-Type: application/json' -d "{\"refresh_token\":\"$PREF\"}")
want "and the spent one is dead" "$C" "401"
C=$(curl -s -o /dev/null -w '%{http_code}' -H 'Authorization: Bearer not-a-token' "$BASE/me")
want "a forged token" "$C" "401"
# Rotating the refresh token above ended that session, which is the point of
# rotation — so the rest of the run needs a patient signed in freshly.
PTOK=$(tok patient@demo.test | grep -o '"access_token":"[^"]*"' | head -1 | cut -d'"' -f4)
P=(-H "Authorization: Bearer $PTOK" -H 'Content-Type: application/json')

step "2. RBAC — the same endpoint, three answers"
C=$(curl -s -o /dev/null -w '%{http_code}' "${O[@]}" "$BASE/patients"); want "owner lists patients" "$C" "200"
C=$(curl -s -o /dev/null -w '%{http_code}' "${R[@]}" "$BASE/patients"); want "receptionist lists patients" "$C" "200"
C=$(curl -s -o /dev/null -w '%{http_code}' "${R[@]}" "$BASE/audit-logs"); want "receptionist reads the audit log" "$C" "403"
C=$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $PTOK" -H 'X-Organization-Id: 1' "$BASE/patients")
want "a patient lists patients" "$C" "403"
N=$(sql "SELECT COUNT(*) FROM roles WHERE organization_id IS NULL")
if [ "${N:-0}" -ge 10 ]; then ok "$N roles are defined"; else bad "only $N roles"; fi
for role in nurse accountant receptionist lab_staff; do
  N=$(sql "SELECT COUNT(*) FROM roles WHERE slug='$role'")
  want "role '$role' exists" "$N" "1"
done

step "3. One clinic cannot see another"
OTHER=$(sql "SELECT id FROM organizations WHERE id <> 1 LIMIT 1")
if [ -n "$OTHER" ]; then
  C=$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $OTOK" \
        -H "X-Organization-Id: $OTHER" "$BASE/organizations/current")
  want "the demo owner reaching clinic $OTHER" "$C" "403"
else
  ok "only one clinic on this install"
fi
OTHERPAT=$(sql "SELECT id FROM patients WHERE organization_id <> 1 LIMIT 1")
if [ -n "$OTHERPAT" ]; then
  C=$(curl -s -o /dev/null -w '%{http_code}' "${O[@]}" "$BASE/patients/$OTHERPAT")
  want "another clinic's patient" "$C" "404"
else
  ok "no other clinic's patient to try"
fi

step "4. Reading a chart is written down (HIPAA \$164.312(b))"
BEFORE=$(sql "SELECT COUNT(*) FROM audit_logs WHERE action='view' AND patient_id=1")
curl -s -o /dev/null "${O[@]}" "$BASE/patients/1"
AFTER=$(sql "SELECT COUNT(*) FROM audit_logs WHERE action='view' AND patient_id=1")
if [ "$AFTER" -gt "$BEFORE" ]; then ok "the read was logged ($BEFORE -> $AFTER)"
else bad "reading a chart left no trace"; fi
ROW=$(sql "SELECT CONCAT(user_id,'|',ip_address IS NOT NULL,'|',route IS NOT NULL) FROM audit_logs WHERE action='view' AND patient_id=1 ORDER BY id DESC LIMIT 1")
echo "$ROW" | grep -q "^[0-9]*|1|1$" && ok "with who, from where, and what they called" || bad "the log entry is thin" "$ROW"

step "5. The patient can obtain their record (GDPR Art. 15 & 20)"
EXP=$(curl -s "${P[@]}" "$BASE/patient/export")
for section in you allergies medical_conditions appointments visits prescriptions lab_orders documents invoices insurance claims; do
  echo "$EXP" | grep -q "\"$section\"" && ok "the export includes '$section'" || bad "'$section' is missing"
done
echo "$EXP" | grep -q 'GDPR Art. 15' && ok "and names the right it answers" || bad "no standard cited"
echo "$EXP" | grep -q '"erasure"' && ok "and explains erasure honestly" || bad "erasure not addressed"

step "6. But only their own"
MRN=$(sql "SELECT mrn FROM patients WHERE id=1")
echo "$EXP" | grep -q "\"$MRN\"" && ok "the export is theirs ($MRN)" || bad "wrong patient exported"
OTHERMRN=$(sql "SELECT mrn FROM patients WHERE organization_id=1 AND id <> 1 LIMIT 1")
if echo "$EXP" | grep -q "\"$OTHERMRN\""; then bad "somebody else's record leaked into it"
else ok "nobody else's record is in it"; fi
C=$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $PTOK" -H 'X-Organization-Id: 1' "$BASE/patients/2/export")
want "a patient exporting another patient" "$C" "403"
C=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/patient/export")
want "with no token at all" "$C" "401"

step "7. Staff can produce it too, and it is audited"
BEFORE=$(sql "SELECT COUNT(*) FROM audit_logs WHERE action='export'")
C=$(curl -s -o /dev/null -w '%{http_code}' "${O[@]}" "$BASE/patients/1/export")
want "the clinic exports it on request" "$C" "200"
AFTER=$(sql "SELECT COUNT(*) FROM audit_logs WHERE action='export'")
if [ "$AFTER" -gt "$BEFORE" ]; then ok "the export is in the audit log ($BEFORE -> $AFTER)"
else bad "a whole record left with no audit entry"; fi

step "8. Unapproved AI drafts stay out of the patient's copy"
N=$(sql "SELECT COUNT(*) FROM clinical_notes WHERE patient_id=1 AND approved_at IS NULL")
if [ "${N:-0}" -gt 0 ]; then
  BODY=$(sql "SELECT LEFT(body, 30) FROM clinical_notes WHERE patient_id=1 AND approved_at IS NULL LIMIT 1")
  if echo "$EXP" | grep -qF "$BODY"; then bad "an unapproved draft is in the export"
  else ok "an unapproved draft is not in the export"; fi
else
  ok "no unapproved drafts to check"
fi

step "9. The erasure answer is one the clinic can keep (GDPR Art. 17)"
# The export tells the patient a clinical record cannot be deleted — Art.
# 17(3) allows that — and then makes two promises in its place: the clinic can
# correct what is wrong, and it can close the app account, with the medical
# record staying where it is. Checking that the paragraph EXISTS is not the
# same as checking the system can do what it says, and only the first was
# checked. So do the second one, end to end, on an account made for it.
STAMP=$(date +%s)
FREEPAT=$(sql "SELECT id FROM patients
                WHERE organization_id = 1 AND user_id IS NULL ORDER BY id DESC LIMIT 1" | tr -d '\r')

if [ -n "$FREEPAT" ]; then
  LINKED=$(curl -s -X POST "${O[@]}" -d "{\"email\":\"erasure$STAMP@test.local\"}" \
      "$BASE/patients/$FREEPAT/account")
  NEWUSER=$(echo "$LINKED" | grep -o '"user_id":[0-9]*' | head -1 | cut -d: -f2)
  TEMPPW=$(echo "$LINKED" | grep -o '"temporary_password":"[^"]*"' | cut -d'"' -f4)

  if [ -n "$NEWUSER" ] && [ -n "$TEMPPW" ]; then
    TOK=$(curl -s -X POST "$BASE/auth/login" -H 'Content-Type: application/json' \
        -d "{\"email\":\"erasure$STAMP@test.local\",\"password\":\"$TEMPPW\"}" \
        | grep -o '"access_token":"[^"]*"' | head -1 | cut -d'"' -f4)
    C=$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $TOK" \
        -H 'X-Organization-Id: 1' "$BASE/patient/dashboard")
    want "the new account reaches its own record" "$C" "200"

    # "Ask the clinic to close your app account."
    curl -s -o /dev/null -X PUT "${O[@]}" -d '{"status":"disabled"}' \
        "$BASE/organizations/current/members/$NEWUSER/status"

    TOK2=$(curl -s -X POST "$BASE/auth/login" -H 'Content-Type: application/json' \
        -d "{\"email\":\"erasure$STAMP@test.local\",\"password\":\"$TEMPPW\"}" \
        | grep -o '"access_token":"[^"]*"' | head -1 | cut -d'"' -f4)
    C=$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer ${TOK2:-none}" \
        -H 'X-Organization-Id: 1' "$BASE/patient/dashboard")
    want "closing it shuts the door" "$C" "403"

    # "...the medical record stays with the clinic." The half that makes the
    # first half lawful: the login goes, the chart does not.
    STILL=$(sql "SELECT status FROM patients WHERE id=$FREEPAT" | tr -d '\r')
    want "and the chart is still there" "$STILL" "active"

    # Put the seat back. `staff` usage counts every ACTIVE membership — a
    # patient login included — so an account left behind by each run walks the
    # clinic past its plan, and the suites that check a downgrade back to
    # Professional start failing for a reason that is nothing to do with them.
    # The membership goes; the chart stays, which is the point being tested.
    curl -s -o /dev/null -X DELETE "${O[@]}" \
        "$BASE/organizations/current/members/$NEWUSER"
    sql "UPDATE patients SET user_id = NULL WHERE id = $FREEPAT" > /dev/null
    sql "DELETE FROM users WHERE id = $NEWUSER" > /dev/null

    LEFT=$(sql "SELECT COUNT(*) FROM organization_users
                 WHERE organization_id = 1 AND user_id = $NEWUSER" | tr -d '\r')
    want "and the suite gives the seat back" "$LEFT" "0"
  else
    bad "could not link an app account to test the promise with"
  fi
else
  bad "no unlinked patient to link an account to"
fi

echo
echo "========================================="
echo "passed: $PASS   failed: $FAIL"
[ "$FAIL" -eq 0 ]
