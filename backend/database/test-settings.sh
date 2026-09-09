#!/usr/bin/env bash
# §21 system settings — and the promise that no secret is on that screen.
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
tok()  { curl -s -X POST "$BASE/auth/login" -H 'Content-Type: application/json' \
           -d "{\"email\":\"$1\",\"password\":\"Password123\"}" \
           | grep -o '"access_token":"[^"]*"' | head -1 | cut -d'"' -f4; }

ADMIN=$(tok admin@mediflow.test)
OWNER=$(tok owner@clinic.test)
[ -z "$ADMIN" ] && { echo "cannot sign in as the platform admin"; exit 1; }
A=(-H "Authorization: Bearer $ADMIN" -H 'Content-Type: application/json')
O=(-H "Authorization: Bearer $OWNER" -H 'X-Organization-Id: 1' -H 'Content-Type: application/json')

echo
echo "[1] The screen is built from what the API describes"
R=$(curl -s "${A[@]}" "$BASE/platform/settings")
for k in platform_name support_email signup_open default_country payment_gateway payment_mode; do
  echo "$R" | grep -q "\"$k\"" && ok "$k is offered" || bad "$k missing" "$(echo "$R" | head -c 200)"
done
echo "$R" | grep -q '"choices"' && ok "choices come with it" || bad "no choices"
echo "$R" | grep -q '"help"'    && ok "and an explanation"  || bad "no help text"

echo
echo "[2] Saving one setting sticks"
curl -s -o /dev/null -X PUT "${A[@]}" "$BASE/platform/settings" \
  -d '{"settings":{"platform_name":"Ariba Clinic Systems"}}'
V=$(sql "SELECT setting_value FROM platform_settings WHERE setting_key='platform_name'")
want "stored in the database" "$V" "Ariba Clinic Systems"
R=$(curl -s "${A[@]}" "$BASE/platform/settings")
echo "$R" | grep -q 'Ariba Clinic Systems' && ok "and read back" || bad "not read back"

echo
echo "[3] A value outside the list is refused, not written"
curl -s -o /dev/null -X PUT "${A[@]}" "$BASE/platform/settings" \
  -d '{"settings":{"payment_mode":"whatever"}}'
V=$(sql "SELECT COALESCE(setting_value,'') FROM platform_settings WHERE setting_key='payment_mode'")
if [ "$V" != "whatever" ]; then ok "payment_mode=whatever was refused"; else bad "a junk mode was stored"; fi

C=$(curl -s -o /dev/null -w '%{http_code}' -X PUT "${A[@]}" "$BASE/platform/settings" \
      -d '{"settings":{"not_a_real_setting":"x"}}')
want "an unknown key" "$C" "422"
N=$(sql "SELECT COUNT(*) FROM platform_settings WHERE setting_key='not_a_real_setting'")
want "and nothing was written for it" "$N" "0"

echo
echo "[4] The gateway can be chosen from the panel"
curl -s -o /dev/null -X PUT "${A[@]}" "$BASE/platform/settings" \
  -d '{"settings":{"payment_gateway":"stub","payment_mode":"sandbox"}}'
PTOK=$(tok patient@demo.test)
S=$(curl -s -H "Authorization: Bearer $PTOK" "$BASE/patient/payments/status")
echo "$S" | grep -q '"gateway":"stub"' && ok "the patient app now sees the stub gateway" \
  || bad "the choice did not reach the app" "$S"

curl -s -o /dev/null -X PUT "${A[@]}" "$BASE/platform/settings" \
  -d '{"settings":{"payment_gateway":""}}'
S=$(curl -s -H "Authorization: Bearer $PTOK" "$BASE/patient/payments/status")
echo "$S" | grep -q '"configured":false' && ok "turning it off reaches the app too" \
  || bad "switching off did not take" "$S"

# Put it back for the rest of the suite.
curl -s -o /dev/null -X PUT "${A[@]}" "$BASE/platform/settings" \
  -d '{"settings":{"payment_gateway":"stub"}}'

echo
echo "[5] No secret is ever on that screen"
# Give the environment a recognisable key, then make sure it cannot be read out.
R=$(curl -s "${A[@]}" "$BASE/platform/settings")
for leak in PAYMENT_SECRET_KEY secret_key client_secret ANTHROPIC DB_PASSWORD; do
  if echo "$R" | grep -qi "\"$leak\"\s*:\s*\"[^\"]\+\""; then bad "$leak is exposed"
  else ok "$leak is not in the response"; fi
done
echo "$R" | grep -q 'credentials_location' && ok "it says where the keys live instead" \
  || bad "no pointer to where credentials belong"
N=$(sql "SELECT COUNT(*) FROM platform_settings WHERE setting_key LIKE '%secret%' OR setting_key LIKE '%key%'")
want "nothing secret-shaped in the table" "$N" "0"

echo
echo "[6] Only the platform admin may touch it"
C=$(curl -s -o /dev/null -w '%{http_code}' "${O[@]}" "$BASE/platform/settings")
want "a clinic owner reading" "$C" "403"
C=$(curl -s -o /dev/null -w '%{http_code}' -X PUT "${O[@]}" "$BASE/platform/settings" \
      -d '{"settings":{"payment_mode":"live"}}')
want "a clinic owner writing" "$C" "403"
C=$(curl -s -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' "$BASE/platform/settings")
want "nobody at all" "$C" "401"

echo
echo "[7] Turning live mode on is written down"
curl -s -o /dev/null -X PUT "${A[@]}" "$BASE/platform/settings" \
  -d '{"settings":{"payment_mode":"live"}}'
N=$(sql "SELECT COUNT(*) FROM audit_logs WHERE resource_type='platform_settings'")
if [ "${N:-0}" -gt 0 ]; then ok "the change is in the audit log ($N entries)"; else bad "not audited"; fi
curl -s -o /dev/null -X PUT "${A[@]}" "$BASE/platform/settings" \
  -d '{"settings":{"payment_mode":"sandbox"}}'
want "and put back to sandbox" "$(sql "SELECT setting_value FROM platform_settings WHERE setting_key='payment_mode'")" "sandbox"

echo
echo "========================================="
echo "passed: $PASS   failed: $FAIL"
[ "$FAIL" -eq 0 ]
