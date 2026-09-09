#!/usr/bin/env bash
# Which doctors are at their desk right now, and what the patient app sees.
#
# Presence is derived from auth_tokens.last_used_at, which every authenticated
# request already touches. There is no status anybody sets, so there is none
# to forget to change.
set -uo pipefail
BASE="${BASE:-http://127.0.0.1:8000/api/v1}"
MYSQL="${MYSQL:-/c/xampp/mysql/bin/mysql.exe}"
DB="${DB:-mediflow}"
PASS=0; FAIL=0
# See the note in the other suites: a bucket somebody else filled makes a
# 429 look like this suite's fault.
reset_limits() { [ -x "$MYSQL" ] && "$MYSQL" -u root "$DB" -e "TRUNCATE TABLE rate_limits;" 2>/dev/null; }
reset_limits
step() { printf '\n\033[1m%s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m  %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m  %s  %s\n' "$1" "${2:-}"; }
want() { if [ "$2" = "$3" ]; then ok "$1 -> $2"; else bad "$1" "got [$2] want [$3]"; fi; }
sql()  { "$MYSQL" -u root "$DB" -N -e "$1" 2>/dev/null | tr -d '\r'; }
login() { curl -s -X POST "$BASE/auth/login" -H 'Content-Type: application/json' \
            -d "{\"email\":\"$1\",\"password\":\"Password123\"}" \
          | grep -o '"access_token":"[^"]*"' | head -1 | cut -d'"' -f4; }
names() { grep -o '"doctor_name":"[^"]*"' | sed 's/.*://; s/"//g'; }

PTOK=$(login patient@demo.test)
[ -z "$PTOK" ] && { echo "cannot sign in as the patient"; exit 1; }
P=(-H "Authorization: Bearer $PTOK")

step "1. Every doctor carries their presence"
BODY=$(curl -s "${P[@]}" "$BASE/patient/doctors")
echo "$BODY" | grep -q '"is_online"'    && ok "the list says who is online"    || bad "no is_online field"
echo "$BODY" | grep -q '"last_seen_at"' && ok "and when each was last seen"    || bad "no last_seen_at"
echo "$BODY" | grep -q '"online_count"' && ok "with a count the app can badge" || bad "no online_count"

step "2. Signing in puts a doctor on the list"
DTOK=$(login doctor@clinic.test)
[ -n "$DTOK" ] && ok "the doctor signed in" || bad "doctor could not sign in"
DNAME=$(sql "SELECT u.name FROM users u WHERE u.email='doctor@clinic.test'")

ONLINE=$(curl -s "${P[@]}" "$BASE/patient/doctors?online=1" | names)
echo "$ONLINE" | grep -qF "$DNAME" && ok "and appears under ?online=1 ($DNAME)" \
                                  || bad "the signed-in doctor is not shown as online"

step "3. Signing out takes them off it"
curl -s -o /dev/null -X POST -H "Authorization: Bearer $DTOK" "$BASE/auth/logout-all"
ONLINE=$(curl -s "${P[@]}" "$BASE/patient/doctors?online=1" | names)
echo "$ONLINE" | grep -qF "$DNAME" && bad "still online after signing out" \
                                  || ok "gone from ?online=1 once signed out"

# The whole point of the design: a revoked token stops counting immediately,
# so signing out means something rather than waiting for a timer.
N=$(sql "SELECT COUNT(*) FROM auth_tokens t JOIN users u ON u.id=t.user_id
          WHERE u.email='doctor@clinic.test' AND t.revoked_at IS NULL")
want "and none of their tokens are live" "$N" "0"

step "4. But they can still be booked for later"
ALL=$(curl -s "${P[@]}" "$BASE/patient/doctors" | names)
echo "$ALL" | grep -qF "$DNAME" && ok "the full list still offers them" \
                                || bad "an offline doctor vanished from booking entirely"

# This is the case that would break a clinic: nobody at their desk at night.
COUNT=$(curl -s "${P[@]}" "$BASE/patient/doctors" | grep -o '"doctor_name"' | wc -l)
[ "${COUNT:-0}" -gt 0 ] && ok "$COUNT doctor(s) bookable regardless of the hour" \
                        || bad "the default list is empty — nobody could book"

step "5. Presence is not a flag anybody sets"
# is_accepting is the doctor's own choice and is separate: a doctor may be at
# their desk and still not taking new patients.
sql "UPDATE doctors SET is_accepting=0 WHERE organization_id=1 AND id=1" >/dev/null
GONE=$(curl -s "${P[@]}" "$BASE/patient/doctors" | names)
sql "UPDATE doctors SET is_accepting=1 WHERE organization_id=1 AND id=1" >/dev/null
FIRST=$(sql "SELECT u.name FROM doctors d JOIN users u ON u.id=d.user_id WHERE d.id=1")
echo "$GONE" | grep -qF "$FIRST" && bad "a doctor not accepting patients is still offered" \
                                 || ok "not accepting patients removes them, online or not"

BACK=$(curl -s "${P[@]}" "$BASE/patient/doctors" | names)
echo "$BACK" | grep -qF "$FIRST" && ok "and they return when accepting again" \
                                 || bad "the doctor did not come back"

step "6. Only this clinic's doctors, online or otherwise"
OTHER=$(sql "SELECT COUNT(*) FROM doctors WHERE organization_id <> 1")
LISTED=$(curl -s "${P[@]}" "$BASE/patient/doctors" | grep -o '"id":[0-9]*' | wc -l)
MINE=$(sql "SELECT COUNT(*) FROM doctors WHERE organization_id=1 AND is_accepting=1")
want "the patient sees only their own clinic's doctors" "$LISTED" "$MINE"

echo
echo "========================================="
echo "passed: $PASS   failed: $FAIL"
[ "$FAIL" -eq 0 ]
