#!/usr/bin/env bash
# A clinic signing up, from nothing to seeing its first patient.
#
#   admin account -> organization -> doctor + staff -> services -> in business
#
# Every step is done as the new clinic, against a clinic that did not exist
# when the script started. Nothing is seeded for it.
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
id_of() { echo "$1" | grep -o "\"$2\":[0-9]*" | head -1 | cut -d: -f2; }

STAMP=$(date +%s)
EMAIL="founder$STAMP@newclinic.test"

step "1. The founder makes an account"
R=$(curl -s -X POST "$BASE/auth/register" -H 'Content-Type: application/json' \
     -d "{\"name\":\"Dr Founder\",\"email\":\"$EMAIL\",\"password\":\"Password123\"}")
TOK=$(echo "$R" | grep -o '"access_token":"[^"]*"' | head -1 | cut -d'"' -f4)
[ -n "$TOK" ] && ok "registered and signed in" || bad "could not register" "$(echo "$R" | head -c 120)"
[ -z "$TOK" ] && { echo; echo "cannot continue"; exit 1; }
A=(-H "Authorization: Bearer $TOK" -H 'Content-Type: application/json')

# Before there is a clinic, there is nothing to be inside of.
C=$(curl -s -o /dev/null -w '%{http_code}' "${A[@]}" "$BASE/patients")
[ "$C" = "403" ] || [ "$C" = "400" ] && ok "with no clinic yet, clinic data is closed -> $C" \
                                     || bad "a user with no organization reached patient data" "got $C"

step "2. They create the clinic"
R=$(curl -s -X POST "${A[@]}" -d "{\"name\":\"New Clinic $STAMP\",\"country_code\":\"PK\"}" "$BASE/organizations")
ORG=$(echo "$R" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)
[ -n "$ORG" ] && ok "organization created (id $ORG)" || bad "no organization" "$(echo "$R" | head -c 150)"
[ -z "$ORG" ] && { echo; echo "cannot continue"; exit 1; }
O=(-H "Authorization: Bearer $TOK" -H "X-Organization-Id: $ORG" -H 'Content-Type: application/json')

# The three things that must be true the instant a clinic exists.
ROLE=$(sql "SELECT r.slug FROM organization_users ou JOIN roles r ON r.id=ou.role_id
             WHERE ou.organization_id=$ORG")
want "the founder is its owner" "$ROLE" "org_owner"
SUB=$(sql "SELECT COUNT(*) FROM subscriptions WHERE organization_id=$ORG")
want "and it has a subscription from minute one" "$SUB" "1"
CUR=$(sql "SELECT COALESCE(o.currency_code,c.currency_code) FROM organizations o
             JOIN countries c ON c.id=o.country_id WHERE o.id=$ORG")
want "billed in its market's currency" "$CUR" "PKR"

# A brand-new clinic must be empty — not sharing anybody else's records.
want "no patients yet"     "$(sql "SELECT COUNT(*) FROM patients WHERE organization_id=$ORG")"     "0"
want "no invoices yet"     "$(sql "SELECT COUNT(*) FROM invoices WHERE organization_id=$ORG")"     "0"
C=$(curl -s -o /dev/null -w '%{http_code}' "${O[@]}" "$BASE/patients")
want "but the patient list opens" "$C" "200"

step "3. They add a doctor and a receptionist"
DOC_ROLE=$(sql "SELECT id FROM roles WHERE slug='doctor' AND organization_id IS NULL")
REC_ROLE=$(sql "SELECT id FROM roles WHERE slug='receptionist' AND organization_id IS NULL")

R=$(curl -s -X POST "${O[@]}" \
     -d "{\"email\":\"doc$STAMP@newclinic.test\",\"name\":\"Dr Ayesha\",\"role_id\":$DOC_ROLE,\"job_title\":\"Dentist\"}" \
     "$BASE/organizations/current/members")
echo "$R" | grep -q 'temporary_password' && ok "the doctor is invited with a temporary password" \
                                         || bad "no invite" "$(echo "$R" | head -c 150)"

R=$(curl -s -X POST "${O[@]}" \
     -d "{\"email\":\"rec$STAMP@newclinic.test\",\"name\":\"Sana Front Desk\",\"role_id\":$REC_ROLE,\"job_title\":\"Receptionist\"}" \
     "$BASE/organizations/current/members")
echo "$R" | grep -q '"user"\|temporary_password' && ok "and so is the receptionist" || bad "receptionist not added"

want "the team is three people" "$(sql "SELECT COUNT(*) FROM organization_users WHERE organization_id=$ORG")" "3"

# §20: employment details can be recorded against a member.
DOC_UID=$(sql "SELECT ou.user_id FROM organization_users ou JOIN roles r ON r.id=ou.role_id
                WHERE ou.organization_id=$ORG AND r.slug='doctor' LIMIT 1")
C=$(curl -s -o /dev/null -w '%{http_code}' -X PUT "${O[@]}" \
     -d '{"employee_no":"EMP-001","department":"Dental","hired_at":"2026-09-01"}' \
     "$BASE/organizations/current/members/$DOC_UID/staff")
want "the doctor's employment record" "$C" "200"

# Being on the team is access; being bookable is a clinical profile. They are
# separate on purpose — a practice manager has the first and not the second.
# The profile is refused until the person is a member, so the order is fixed.
R=$(curl -s -X POST "${O[@]}" \
     -d '{"user_id":999999,"specialty":"Dentistry"}' "$BASE/doctors")
echo "$R" | grep -q 'not a member' && ok "a stranger cannot be given a doctor profile" \
                                   || bad "a non-member got a doctor profile"

R=$(curl -s -X POST "${O[@]}" \
     -d "{\"user_id\":$DOC_UID,\"specialty\":\"Dentistry\",\"consultation_fee\":\"2000.00\",\"slot_minutes\":30,\"room\":\"1\"}" \
     "$BASE/doctors")
DOC=$(id_of "$R" id)
[ -n "$DOC" ] && ok "the doctor now has a clinical profile (id $DOC)" \
              || bad "no doctor profile" "$(echo "$R" | head -c 150)"
C=$(curl -s -o /dev/null -w '%{http_code}' -X POST "${O[@]}" \
     -d "{\"user_id\":$DOC_UID,\"specialty\":\"Dentistry\"}" "$BASE/doctors")
want "and cannot be given a second one" "$C" "409"

step "4. They configure what the clinic charges for"
R=$(curl -s -X POST "${O[@]}" \
     -d '{"code":"CONSULT-GEN","name":"General consultation","category":"consultation","is_taxable":true,"price":"2000.00"}' \
     "$BASE/services")
SVC=$(id_of "$R" id)
[ -n "$SVC" ] && ok "a service is on the price list (id $SVC)" || bad "service not created" "$(echo "$R" | head -c 150)"

C=$(curl -s -o /dev/null -w '%{http_code}' "${O[@]}" "$BASE/services")
want "the catalogue is readable" "$C" "200"

step "5. The clinic is in business"
R=$(curl -s -X POST "${O[@]}" \
     -d '{"first_name":"First","last_name":"Patient","phone":"03001234567","gender":"female"}' \
     "$BASE/patients")
PAT=$(id_of "$R" id)
[ -n "$PAT" ] && ok "its first patient is registered (id $PAT)" || bad "could not register a patient"

MRN=$(sql "SELECT mrn FROM patients WHERE id=$PAT")
# MRNs restart per clinic — two clinics both having P-000001 is correct.
want "who gets this clinic's first MRN" "$MRN" "P-000001"

# There is no generic /dashboard: the clinic admin screen reads the
# organization, and the doctor's day is its own endpoint.
C=$(curl -s -o /dev/null -w '%{http_code}' "${O[@]}" "$BASE/organizations/current")
want "the clinic's own settings open" "$C" "200"

# The real proof that onboarding worked: the new doctor can be booked.
SLOTS=$(curl -s "${O[@]}" "$BASE/doctors/$DOC/available-slots?date=$(date -d '+1 day' +%Y-%m-%d 2>/dev/null || date +%Y-%m-%d)")
echo "$SLOTS" | grep -q '"slots"' && ok "the new doctor has a bookable calendar" \
                                  || bad "no slots for the new doctor" "$(echo "$SLOTS" | head -c 120)"
curl -s "${O[@]}" "$BASE/doctors" | grep -q '"doctor_name"\|"specialty"' \
  && ok "and appears on the clinic's doctor list" || bad "the doctor is not listed"

step "6. And it cannot see anybody else's clinic"
C=$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $TOK" \
     -H 'X-Organization-Id: 1' "$BASE/patients")
want "reaching the demo clinic" "$C" "403"
OTHER=$(sql "SELECT id FROM patients WHERE organization_id=1 LIMIT 1")
C=$(curl -s -o /dev/null -w '%{http_code}' "${O[@]}" "$BASE/patients/$OTHER")
want "reading a patient from another clinic" "$C" "404"

echo
echo "========================================="
echo "passed: $PASS   failed: $FAIL"
[ "$FAIL" -eq 0 ]
