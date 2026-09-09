#!/usr/bin/env bash
# §20 database architecture: the tables the spec names, the shape they are in,
# and the one that was defined but never used.
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
sql()  { "$MYSQL" -u root "$DB" -N -e "$1" 2>/dev/null | tr -d '\r'; }

step "1. Every table §20 names exists"
for t in users roles permissions organizations organization_users patients doctors \
         staff appointments encounters diagnoses medications prescriptions \
         lab_orders lab_results procedures medical_documents services \
         service_prices invoices invoice_items payments refunds \
         insurance_providers insurance_policies claims claim_items \
         notifications audit_logs subscriptions subscription_items; do
  N=$(sql "SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema='$DB' AND table_name='$t'")
  [ "${N:-0}" = "1" ] && ok "table '$t'" || bad "table '$t' is missing"
done

step "2. The shape holds up"
# Tenant isolation is structural: every clinic-owned table must be scopable.
#
# Two kinds of table are exempt, and the difference between them matters.
# GLOBAL tables are owned by nobody — reference data and platform machinery.
# IDENTITY tables are owned by a person rather than a clinic: a login session,
# a reset code, a phone. Somebody who works at two clinics carries one phone,
# so hanging a device off an organisation would be a lie about who owns it.
#
# An exemption list is exactly where a real mistake hides: forget
# organization_id on a genuinely clinic-owned table, drop its name in here,
# and the test goes quiet. So the identity list has to earn itself — the
# second check below makes every name on it prove it is keyed to a person.
GLOBAL="'migrations','rate_limits','countries','plans','users','roles',
        'permissions','role_permissions','platform_settings','organizations'"
IDENTITY="'auth_tokens','password_resets','device_tokens'"

UNSCOPED=$(sql "
  SELECT COUNT(*) FROM information_schema.tables t
   WHERE t.table_schema='$DB' AND t.table_type='BASE TABLE'
     AND t.table_name NOT IN ($GLOBAL, $IDENTITY)
     AND NOT EXISTS (SELECT 1 FROM information_schema.columns c
                      WHERE c.table_schema='$DB' AND c.table_name=t.table_name
                        AND c.column_name='organization_id')")
want "every tenant table carries organization_id" "$UNSCOPED" "0"

IMPOSTOR=$(sql "
  SELECT COUNT(*) FROM information_schema.tables t
   WHERE t.table_schema='$DB' AND t.table_type='BASE TABLE'
     AND t.table_name IN ($IDENTITY)
     AND NOT EXISTS (SELECT 1 FROM information_schema.columns c
                      WHERE c.table_schema='$DB' AND c.table_name=t.table_name
                        AND c.column_name='user_id')")
want "and each exempt identity table is keyed to a person" "$IMPOSTOR" "0"

FKS=$(sql "SELECT COUNT(*) FROM information_schema.table_constraints
            WHERE table_schema='$DB' AND constraint_type='FOREIGN KEY'")
if [ "${FKS:-0}" -ge 90 ]; then ok "$FKS foreign keys are declared"; else bad "only $FKS foreign keys"; fi

# MyISAM would silently drop both the transactions and the foreign keys.
NONINNO=$(sql "SELECT COUNT(*) FROM information_schema.tables
                WHERE table_schema='$DB' AND engine <> 'InnoDB'")
want "every table is InnoDB" "$NONINNO" "0"

step "3. staff is a table, not a diagram"
TOK=$(curl -s -X POST "$BASE/auth/login" -H 'Content-Type: application/json' \
        -d '{"email":"owner@clinic.test","password":"Password123"}' \
      | grep -o '"access_token":"[^"]*"' | head -1 | cut -d'"' -f4)
[ -z "$TOK" ] && { echo "cannot sign in"; exit 1; }
O=(-H "Authorization: Bearer $TOK" -H 'X-Organization-Id: 1' -H 'Content-Type: application/json')
M="$BASE/organizations/current/members"

UID_A=$(curl -s "${O[@]}" "$M" | grep -o '"user_id":[0-9]*' | head -1 | cut -d: -f2)
UID_B=$(curl -s "${O[@]}" "$M" | grep -o '"user_id":[0-9]*' | sed -n 2p | cut -d: -f2)
[ -z "$UID_A" ] && { echo "no members to test with"; exit 1; }

C=$(curl -s -o /dev/null -w '%{http_code}' -X PUT "${O[@]}" \
      -d '{"employee_no":"EMP-T001","department":"Front Desk","designation":"Office Manager","hired_at":"2024-03-01"}' \
      "$M/$UID_A/staff")
want "record somebody's employment" "$C" "200"

BODY=$(curl -s "${O[@]}" "$M/$UID_A/staff")
echo "$BODY" | grep -q '"employee_no":"EMP-T001"' && ok "it reads back"        || bad "did not read back"
echo "$BODY" | grep -q '"hired_at":"2024-03-01"'  && ok "with the start date"  || bad "no start date"

# It has to reach the row the database actually holds, not just the response.
N=$(sql "SELECT COUNT(*) FROM staff WHERE organization_id=1 AND employee_no='EMP-T001'")
want "the row is in the staff table" "$N" "1"

curl -s "${O[@]}" "$M" | grep -q '"employee_no":"EMP-T001"' \
  && ok "and shows on the team list" || bad "not on the team list"

# An employee number naming two people is the thing worth refusing.
if [ -n "$UID_B" ]; then
  C=$(curl -s -o /dev/null -w '%{http_code}' -X PUT "${O[@]}" \
        -d '{"employee_no":"EMP-T001"}' "$M/$UID_B/staff")
  want "the same number for somebody else" "$C" "409"
else
  ok "only one member here, nothing to collide with"
fi

# Updating must not make a second row — there is one record per person.
curl -s -o /dev/null -X PUT "${O[@]}" \
     -d '{"employee_no":"EMP-T001","department":"Reception"}' "$M/$UID_A/staff"
N=$(sql "SELECT COUNT(*) FROM staff WHERE organization_id=1 AND user_id=$UID_A")
want "updating leaves one record" "$N" "1"
D=$(sql "SELECT department FROM staff WHERE organization_id=1 AND user_id=$UID_A")
want "and the change took" "$D" "Reception"

step "4. Employment details are not access"
# A member who does not exist has no employment record to read.
C=$(curl -s -o /dev/null -w '%{http_code}' "${O[@]}" "$M/999999/staff")
want "a stranger's record" "$C" "404"
C=$(curl -s -o /dev/null -w '%{http_code}' "$M/$UID_A/staff")
want "and it needs a token" "$C" "401"

# Tidy up so re-runs start clean.
sql "DELETE FROM staff WHERE organization_id=1 AND employee_no='EMP-T001'" >/dev/null

step "5. Every medical record says whose it is and who wrote it"
# §5 names twelve entities and says each sensitive record carries the clinic,
# the patient, an author, and timestamps. Tenant scoping is checked above;
# this is the rest of that sentence, and nothing was checking it.
#
# There is deliberately no exemption list. The rule carries its own:
#
#   a table with a patient_id IS a patient record, so it must name an author
#   a table without one is a catalogue, and a catalogue has no author to name
#
# `medications` is the only one of the twelve without a patient, and it is a
# price-list of drugs. So the list cannot be padded to hide a mistake: drop
# patient_id from a real record and it stops being asked for an author, but
# the patient check fails first and says so.
has_col() { sql "SELECT COUNT(*) FROM information_schema.columns
                  WHERE table_schema='$DB' AND table_name='$1' AND column_name='$2'"; }

for t in patients encounters diagnoses medications prescriptions lab_orders \
         lab_results procedures clinical_notes medical_documents allergies \
         medical_conditions; do

  [ "$(has_col "$t" organization_id)" = "1" ] \
    && ok "$t is a clinic's" || bad "$t has no organization_id"

  CA=$(has_col "$t" created_at); UA=$(has_col "$t" updated_at)
  [ "$CA" = "1" ] && [ "$UA" = "1" ] \
    && ok "$t is timestamped" || bad "$t is missing created_at or updated_at"

  # The patient itself is the one row that cannot point at a patient.
  if [ "$t" = "patients" ]; then
    PATIENT=1
  else
    PATIENT=$(has_col "$t" patient_id)
  fi

  if [ "$PATIENT" = "1" ]; then
    # Named for what it means rather than uniformly: a lab result is REPORTED
    # by somebody, a document is UPLOADED. Both are the author.
    AUTHOR=0
    for c in created_by uploaded_by reported_by; do
      [ "$(has_col "$t" "$c")" = "1" ] && AUTHOR=1
    done
    [ "$AUTHOR" = "1" ] && ok "$t names an author" \
                        || bad "$t is a patient record with no author"
  else
    [ "$(has_col "$t" created_by)" = "0" ] \
      && ok "$t is a catalogue, and carries no patient" \
      || bad "$t has an author but no patient — is it a record or a catalogue?"
  fi
done

echo
echo "========================================="
echo "passed: $PASS   failed: $FAIL"
[ "$FAIL" -eq 0 ]
