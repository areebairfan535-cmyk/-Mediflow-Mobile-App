#!/usr/bin/env bash
# Notifications leaving the app and arriving on the phone (§20).
#
# The in-app inbox only reaches somebody who has already opened the app. A
# reminder for tomorrow has to arrive when the app is shut, or it is not a
# reminder. This covers the half that makes that possible: a device saying
# where to reach it, and a channel that actually sends.
#
# No real phone is buzzed. PUSH_ENDPOINT points at a stub that answers in the
# push service's own shape.
set -uo pipefail
BASE="${BASE:-http://127.0.0.1:8000/api/v1}"
MYSQL="${MYSQL:-/c/xampp/mysql/bin/mysql.exe}"
DB="${DB:-mediflow}"
PHP="${PHP:-C:/xampp/php/php.exe}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WHERE="${WHERE:-C:/Users/Mr Shahram/mediflow/backend}"
# A port of its own, so a stray server from another suite cannot be mistaken
# for this one.
PORT="${PUSH_MOCK_PORT:-8137}"
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

STAMP=$(date +%s)
TOKEN="ExponentPushToken[suite$STAMP]"
DEAD="ExponentPushToken[DEAD-$STAMP]"

PTOK=$(curl -s -X POST "$BASE/auth/login" -H 'Content-Type: application/json' \
         -d '{"email":"patient@demo.test","password":"Password123"}' \
       | grep -o '"access_token":"[^"]*"' | head -1 | cut -d'"' -f4)
[ -z "$PTOK" ] && { echo "cannot sign in"; exit 1; }
P=(-H "Authorization: Bearer $PTOK" -H 'Content-Type: application/json')
UID_P=$(sql "SELECT user_id FROM patients WHERE user_id IS NOT NULL LIMIT 1")

# ---- a stand-in for the push service -------------------------------------
MOCK="$HERE/.pushmock"
mkdir -p "$MOCK"
cat > "$MOCK/index.php" <<'MOCKPHP'
<?php
$body = json_decode(file_get_contents('php://input'), true) ?: [];
file_put_contents(__DIR__ . '/last.json', json_encode($body));
$data = [];
foreach ($body as $m) {
    $data[] = str_contains((string) ($m['to'] ?? ''), 'DEAD')
        ? ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']]
        : ['status' => 'ok', 'id' => 'x'];
}
header('Content-Type: application/json');
echo json_encode(['data' => $data]);
MOCKPHP
rm -f "$MOCK/last.json"
"$PHP" -S 127.0.0.1:$PORT "$MOCK/index.php" >/dev/null 2>&1 &
MOCKPID=$!
cleanup() { kill "$MOCKPID" 2>/dev/null; sql "DELETE FROM device_tokens WHERE token IN ('$TOKEN','$DEAD')" >/dev/null; }
trap cleanup EXIT
sleep 2

# Prove the stub answering us is OURS. A stale server left on this port from
# an earlier run answers just as convincingly, and every assertion below would
# then be measuring somebody else's process — which is exactly what happened
# the first time this suite ran.
PROBE=$(curl -s -m 3 -X POST -H 'Content-Type: application/json' \
          -d '[{"to":"probe"}]' "http://127.0.0.1:$PORT/" 2>/dev/null)
if ! echo "$PROBE" | grep -q '"status":"ok"'; then
  echo "  the stub did not start on port $PORT — is something else using it?"
  exit 1
fi
if [ ! -f "$MOCK/last.json" ]; then
  echo "  something else is listening on port $PORT; refusing to test against it"
  exit 1
fi
rm -f "$MOCK/last.json"
ok "the push stub is ours, on port $PORT"

step "1. A phone says where to reach it"
R=$(curl -s -X POST "${P[@]}" \
     -d "{\"token\":\"$TOKEN\",\"platform\":\"android\",\"device_name\":\"Suite Phone\"}" \
     "$BASE/me/devices")
echo "$R" | grep -q '"device"' && ok "the device registered" || bad "registration failed" "$(echo "$R" | head -c 120)"
want "and is stored once" "$(sql "SELECT COUNT(*) FROM device_tokens WHERE token='$TOKEN'")" "1"

# The token is a delivery address; it should not travel back out.
# -F throughout: a push token contains [ and ], and plain grep reads those
# as a character class — so a literal check silently matches nothing and
# a "the token is absent" assertion passes without testing anything.
echo "$R" | grep -qF "$TOKEN" && bad "the push token is echoed back" || ok "and the token is not echoed back"
curl -s "${P[@]}" "$BASE/me/devices" | grep -qF "$TOKEN" \
  && bad "the token is listed" || ok "nor listed"

step "2. Registering again is the same device, not another one"
curl -s -o /dev/null -X POST "${P[@]}" \
  -d "{\"token\":\"$TOKEN\",\"platform\":\"android\",\"device_name\":\"Suite Phone\"}" \
  "$BASE/me/devices"
want "still one row" "$(sql "SELECT COUNT(*) FROM device_tokens WHERE token='$TOKEN'")" "1"

step "3. A notification actually leaves"
OUT=$(cd "$WHERE" && PUSH_ENDPOINT=http://127.0.0.1:$PORT/ "$PHP" -r '
require "'"$WHERE"'/bootstrap/app.php";
echo (new App\Services\Notifications\PushChannel())->send([
  "user_id" => '"$UID_P"', "id" => 99,
  "title" => "Prescription ready",
  "body"  => "Dr. Ayesha Khan issued your prescription.",
  "event" => "prescription.issued",
  "subject_type" => "prescription", "subject_id" => 55,
]);' 2>&1 | tail -1)
want "the channel reports sent" "$OUT" "sent"

SENT=$(cat "$MOCK/last.json" 2>/dev/null)
echo "$SENT" | grep -qF "$TOKEN"              && ok "addressed to this phone"      || bad "wrong recipient"
echo "$SENT" | grep -q 'Prescription ready'  && ok "with the title on it"         || bad "no title"
echo "$SENT" | grep -q '"sound":"default"'   && ok "and a sound"                  || bad "silent"
# Without the subject a tap can only open a generic list.
echo "$SENT" | grep -q '"subject_type":"prescription"' && ok "and what to open when tapped" \
                                                       || bad "no subject in the payload"

step "4. Nobody without a phone is a failure"
OUT=$(cd "$WHERE" && PUSH_ENDPOINT=http://127.0.0.1:$PORT/ "$PHP" -r '
require "'"$WHERE"'/bootstrap/app.php";
echo (new App\Services\Notifications\PushChannel())->send(["user_id" => 999999, "title" => "T", "body" => "B"]);' 2>&1 | tail -1)
want "a person with no device is skipped" "$OUT" "skipped"

step "5. A dead token retires itself"
sql "INSERT INTO device_tokens (user_id, token, platform, device_name, last_seen_at, created_at, updated_at)
     VALUES ($UID_P, '$DEAD', 'ios', 'Uninstalled', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())" >/dev/null
BEFORE=$(sql "SELECT COUNT(*) FROM device_tokens WHERE user_id=$UID_P AND revoked_at IS NULL")
(cd "$WHERE" && PUSH_ENDPOINT=http://127.0.0.1:$PORT/ "$PHP" -r '
require "'"$WHERE"'/bootstrap/app.php";
(new App\Services\Notifications\PushChannel())->send(["user_id" => '"$UID_P"', "title" => "T", "body" => "B"]);' >/dev/null 2>&1)
AFTER=$(sql "SELECT COUNT(*) FROM device_tokens WHERE user_id=$UID_P AND revoked_at IS NULL")
[ "${AFTER:-0}" -lt "${BEFORE:-0}" ] && ok "the uninstalled device was retired ($BEFORE -> $AFTER)" \
                                     || bad "a dead token would be retried for ever"
REASON=$(sql "SELECT last_error FROM device_tokens WHERE token='$DEAD'")
[ -n "$REASON" ] && ok "with the reason recorded: $REASON" || bad "no reason recorded"
# And the working phone is untouched.
want "the live phone is still live" "$(sql "SELECT revoked_at IS NULL FROM device_tokens WHERE token='$TOKEN'")" "1"

step "6. Signing out everywhere silences the phones too"
curl -s -o /dev/null -X POST -H "Authorization: Bearer $PTOK" "$BASE/auth/logout-all"
want "no live device left" "$(sql "SELECT COUNT(*) FROM device_tokens WHERE user_id=$UID_P AND revoked_at IS NULL")" "0"

step "7. One account cannot silence another's phone"
PTOK=$(curl -s -X POST "$BASE/auth/login" -H 'Content-Type: application/json' \
         -d '{"email":"patient@demo.test","password":"Password123"}' \
       | grep -o '"access_token":"[^"]*"' | head -1 | cut -d'"' -f4)
OTHER=$(curl -s -X POST "$BASE/auth/login" -H 'Content-Type: application/json' \
         -d '{"email":"owner@clinic.test","password":"Password123"}' \
       | grep -o '"access_token":"[^"]*"' | head -1 | cut -d'"' -f4)
curl -s -o /dev/null -X POST -H "Authorization: Bearer $PTOK" -H 'Content-Type: application/json' \
  -d "{\"token\":\"$TOKEN\",\"platform\":\"android\"}" "$BASE/me/devices"
DID=$(sql "SELECT id FROM device_tokens WHERE token='$TOKEN'")
C=$(curl -s -o /dev/null -w '%{http_code}' -X DELETE -H "Authorization: Bearer $OTHER" "$BASE/me/devices/$DID")
want "somebody else's device id" "$C" "404"
want "and it is still live" "$(sql "SELECT revoked_at IS NULL FROM device_tokens WHERE token='$TOKEN'")" "1"
C=$(curl -s -o /dev/null -w '%{http_code}' -X DELETE -H "Authorization: Bearer $PTOK" "$BASE/me/devices/$DID")
want "but its owner may silence it" "$C" "200"

echo
echo "========================================="
echo "passed: $PASS   failed: $FAIL"
[ "$FAIL" -eq 0 ]
