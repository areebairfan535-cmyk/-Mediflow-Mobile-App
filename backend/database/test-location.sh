#!/usr/bin/env bash
# Exercises the doctor location filter added for §3.
set -uo pipefail
BASE="${BASE:-http://127.0.0.1:8000/api/v1}"
PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m  %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m  %s  %s\n' "$1" "${2:-}"; }
want() { if [ "$2" = "$3" ]; then ok "$1 -> $2"; else bad "$1" "got [$2] want [$3]"; fi; }

# A patient signs in to their own app.
LOGIN=$(curl -s -X POST "$BASE/auth/login" -H 'Content-Type: application/json' \
  -d '{"email":"patient@demo.test","password":"Password123"}')
TOKEN=$(echo "$LOGIN" | grep -o "\"access_token\":\"[^\"]*\"" | head -1 | cut -d'"' -f4)
if [ -z "$TOKEN" ]; then
  echo "could not sign in a patient:"; echo "$LOGIN" | head -c 400; echo; exit 1
fi
AUTH=(-H "Authorization: Bearer $TOKEN")

echo
echo "[1] The filter lists only values the clinic actually has"
F=$(curl -s "${AUTH[@]}" "$BASE/patient/doctors/filters")
echo "$F" | grep -q '"Main Clinic"'     && ok "Main Clinic is offered"     || bad "Main Clinic missing" "$F"
echo "$F" | grep -q '"Gulberg Branch"'  && ok "Gulberg Branch is offered"  || bad "Gulberg Branch missing" "$F"
echo "$F" | grep -q '"Endodontist"'     && ok "specialties come back too"  || bad "specialties missing" "$F"

echo
echo "[2] Filtering by location narrows the list"
ALL=$(curl -s "${AUTH[@]}" "$BASE/patient/doctors" | grep -o '"doctor_name"' | wc -l | tr -d ' ')
ONE=$(curl -s "${AUTH[@]}" "$BASE/patient/doctors?location=Main%20Clinic")
N=$(echo "$ONE" | grep -o '"doctor_name"' | wc -l | tr -d ' ')
want "unfiltered doctor count" "$ALL" "2"
want "location=Main Clinic"    "$N"   "1"
echo "$ONE" | grep -q 'Ayesha' && ok "and it is the right doctor" || bad "wrong doctor" "$ONE"

echo
echo "[3] Location is returned on each doctor"
echo "$ONE" | grep -q '"location":"Main Clinic"' && ok "location is in the payload" || bad "no location field" "$ONE"

echo
echo "[4] Specialty and location combine"
BOTH=$(curl -s "${AUTH[@]}" "$BASE/patient/doctors?specialty=Endodontist&location=Gulberg%20Branch")
N=$(echo "$BOTH" | grep -o '"doctor_name"' | wc -l | tr -d ' ')
want "a combination nobody matches" "$N" "0"

echo
echo "[5] Free text still searches location"
T=$(curl -s "${AUTH[@]}" "$BASE/patient/doctors?search=Gulberg")
N=$(echo "$T" | grep -o '"doctor_name"' | wc -l | tr -d ' ')
want "search=Gulberg" "$N" "1"

echo
echo "[6] A bad value is rejected, not ignored"
LONG=$(printf 'x%.0s' $(seq 1 200))
CODE=$(curl -s -o /dev/null -w '%{http_code}' "${AUTH[@]}" "$BASE/patient/doctors?location=$LONG")
want "location over 120 chars" "$CODE" "422"

echo
echo "[7] It is still behind auth"
CODE=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/patient/doctors/filters")
want "filters without a token" "$CODE" "401"

echo
echo "========================================="
echo "passed: $PASS   failed: $FAIL"
[ "$FAIL" -eq 0 ]
