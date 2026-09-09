#!/usr/bin/env bash
# The patient self-enrolment door added for §3 — /auth/claim.
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
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m  %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m  %s  %s\n' "$1" "${2:-}"; }
want() { if [ "$2" = "$3" ]; then ok "$1 -> $2"; else bad "$1" "got [$2] want [$3]"; fi; }
sql()  { "$MYSQL" -u root "$DB" -N -e "$1"; }
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
post() { curl -s -X POST "$1" -H 'Content-Type: application/json' -d "$2"; }
pcode(){ curl -s -o /dev/null -w '%{http_code}' -X POST "$1" -H 'Content-Type: application/json' -d "$2"; }

STAMP=$(date +%s)

# A chart the clinic made, with nobody signed in to it yet.
sql "INSERT INTO patients (organization_id, mrn, first_name, last_name, date_of_birth,
       gender, phone, status, created_at, updated_at)
     VALUES (1, 'CLAIM$STAMP', 'Sana', 'Malik', '1994-03-21', 'female', '03001234567',
             'active', NOW(), NOW())"
PID=$(sql "SELECT id FROM patients WHERE mrn='CLAIM$STAMP'")
echo "  (test chart id $PID, mrn CLAIM$STAMP)"

echo
echo "[1] Registering still grants nothing (sec 22)"
R=$(post "$BASE/auth/register" "{\"name\":\"Nobody\",\"email\":\"nobody$STAMP@t.test\",\"password\":\"Password123\"}")
echo "$R" | grep -q '"organizations":\[\]' && ok "register returns no memberships" || bad "register granted a membership" "$R"

echo
echo "[2] Wrong details are refused, and say nothing useful"
C=$(pcode "$BASE/auth/claim" "{\"mrn\":\"NOSUCH$STAMP\",\"date_of_birth\":\"1994-03-21\",\"email\":\"a$STAMP@t.test\",\"password\":\"Password123\"}")
want "unknown patient ID" "$C" "422"
C=$(pcode "$BASE/auth/claim" "{\"mrn\":\"CLAIM$STAMP\",\"date_of_birth\":\"1980-01-01\",\"email\":\"b$STAMP@t.test\",\"password\":\"Password123\"}")
want "right ID, wrong date of birth" "$C" "422"

R=$(post "$BASE/auth/claim" "{\"mrn\":\"NOSUCH$STAMP\",\"date_of_birth\":\"1994-03-21\",\"email\":\"c$STAMP@t.test\",\"password\":\"Password123\"}")
R2=$(post "$BASE/auth/claim" "{\"mrn\":\"CLAIM$STAMP\",\"date_of_birth\":\"1980-01-01\",\"email\":\"d$STAMP@t.test\",\"password\":\"Password123\"}")
if [ "$R" = "$R2" ]; then ok "both wrong answers are identical (no enumeration)"
else bad "the two failures differ" "$R // $R2"; fi

echo
echo "[3] The right details attach the chart"
R=$(post "$BASE/auth/claim" "{\"mrn\":\"CLAIM$STAMP\",\"date_of_birth\":\"1994-03-21\",\"email\":\"sana$STAMP@t.test\",\"password\":\"Password123\"}")
echo "$R" | grep -q '"access_token"' && ok "tokens are issued" || bad "no tokens" "$R"
echo "$R" | grep -q '"organization_id":1' && ok "and the clinic is attached" || bad "no membership" "$R"
LINKED=$(sql "SELECT user_id FROM patients WHERE id=$PID")
[ -n "$LINKED" ] && [ "$LINKED" != "NULL" ] && ok "the chart now points at the account" || bad "chart still unlinked"
NAME=$(sql "SELECT u.name FROM users u WHERE u.id=$LINKED")
want "name taken from the chart" "$NAME" "Sana Malik"

echo
echo "[4] It cannot be claimed twice"
C=$(pcode "$BASE/auth/claim" "{\"mrn\":\"CLAIM$STAMP\",\"date_of_birth\":\"1994-03-21\",\"email\":\"again$STAMP@t.test\",\"password\":\"Password123\"}")
want "second claim on the same chart" "$C" "422"

echo
echo "[5] The new account can only see its own clinic"
TOK=$(echo "$R" | grep -o '"access_token":"[^"]*"' | head -1 | cut -d'"' -f4)
C=$(code -H "Authorization: Bearer $TOK" "$BASE/patient/dashboard")
want "its own dashboard" "$C" "200"
OTHER=$(sql "SELECT id FROM organizations WHERE id <> 1 ORDER BY id LIMIT 1")
if [ -n "$OTHER" ]; then
  C=$(code -H "Authorization: Bearer $TOK" -H "X-Organization-Id: $OTHER" "$BASE/organizations/current")
  want "another clinic (org $OTHER)" "$C" "403"
else
  ok "only one clinic on this install — nothing to cross into"
fi

echo
echo "[6] A taken email is told plainly, not silently swallowed"
sql "INSERT INTO patients (organization_id, mrn, first_name, last_name, date_of_birth,
       gender, status, created_at, updated_at)
     VALUES (1, 'CLAIM2$STAMP', 'Omar', 'Raza', '1990-05-05', 'male', 'active', NOW(), NOW())"
C=$(pcode "$BASE/auth/claim" "{\"mrn\":\"CLAIM2$STAMP\",\"date_of_birth\":\"1990-05-05\",\"email\":\"sana$STAMP@t.test\",\"password\":\"Password123\"}")
want "email already in use" "$C" "409"
STILL=$(sql "SELECT user_id FROM patients WHERE mrn='CLAIM2$STAMP'")
[ -z "$STILL" ] || [ "$STILL" = "NULL" ] && ok "and that chart was left alone" || bad "chart was linked anyway"

# tidy up
sql "DELETE FROM organization_users WHERE user_id IN (SELECT id FROM users WHERE email LIKE '%$STAMP@t.test')" >/dev/null 2>&1
sql "UPDATE patients SET user_id = NULL WHERE mrn LIKE 'CLAIM%$STAMP'" >/dev/null 2>&1
sql "DELETE FROM patients WHERE mrn LIKE 'CLAIM%$STAMP'" >/dev/null 2>&1
sql "DELETE FROM users WHERE email LIKE '%$STAMP@t.test'" >/dev/null 2>&1

echo
echo "========================================="
echo "passed: $PASS   failed: $FAIL"
[ "$FAIL" -eq 0 ]
