#!/usr/bin/env bash
# End-to-end smoke test for the Phase 1 foundation.
#
#   bash database/smoke-test.sh
#
# Requires the API running on http://127.0.0.1:8000 and a seeded database.
# Exits non-zero on the first unmet expectation.

set -uo pipefail

BASE="${BASE:-http://127.0.0.1:8000/api/v1}"
MYSQL="${MYSQL:-/c/xampp/mysql/bin/mysql.exe}"
DB="${DB:-mediflow}"
PASS=0
FAIL=0

# This suite makes ~15 auth calls, well over RATE_LIMIT_AUTH_MAX (10 per
# minute). That limit is correct for production and should NOT be relaxed in
# .env just to make tests pass — so the test clears its own buckets instead.
if [ -x "$MYSQL" ]; then
  "$MYSQL" -u root "$DB" -e 'TRUNCATE TABLE rate_limits;' 2>/dev/null \
    && echo "(rate-limit buckets cleared)"
fi

pass() { PASS=$((PASS + 1)); printf '  \033[32mPASS\033[0m  %s\n' "$1"; }
fail() { FAIL=$((FAIL + 1)); printf '  \033[31mFAIL\033[0m  %s\n' "$1"; [ -n "${2:-}" ] && printf '        %s\n' "$2"; }

# expect <label> <actual-status> <expected-status> [body]
expect() {
  if [ "$2" = "$3" ]; then pass "$1 -> $2"; else fail "$1 -> got $2, want $3" "${4:-}"; fi
}

# api <METHOD> <path> [json-body] [extra curl args...]
# Prints "<status>\n<body>"
api() {
  local method="$1" path="$2" body="${3:-}"; shift 3 || shift $#
  if [ -n "$body" ]; then
    curl -s -w '\n%{http_code}' -X "$method" "$BASE$path" \
      -H 'Content-Type: application/json' -d "$body" "$@"
  else
    curl -s -w '\n%{http_code}' -X "$method" "$BASE$path" "$@"
  fi
}

status_of() { printf '%s' "$1" | tail -n1; }
body_of()   { printf '%s' "$1" | sed '$d'; }

# Minimal JSON string extractor: jq is not guaranteed on a Windows box.
jval() { printf '%s' "$1" | grep -o "\"$2\":\"[^\"]*\"" | head -1 | sed "s/.*\"$2\":\"//; s/\"$//"; }
jnum() { printf '%s' "$1" | grep -o "\"$2\":[0-9]*" | head -1 | sed "s/.*://"; }

# One-value SQL, for the handful of facts the API deliberately does not expose.
sql() { "$MYSQL" -u root "$DB" -N -e "$1"; }

echo
reset_limits() {
  [ -x "$MYSQL" ] && "$MYSQL" -u root "$DB" -e "TRUNCATE TABLE rate_limits;" 2>/dev/null
}

echo "MediFlow Phase 1 — smoke test"
echo "============================="

# ---------------------------------------------------------------
echo
echo "[1] Health & routing"
R=$(api GET /health); expect "GET /health" "$(status_of "$R")" "200"

R=$(api GET /nope); expect "unknown route is 404" "$(status_of "$R")" "404"

R=$(api GET /auth/login); expect "wrong method is 405" "$(status_of "$R")" "405"

# ---------------------------------------------------------------
echo
echo "[2] Authentication"

R=$(api POST /auth/login '{"email":"owner@clinic.test","password":"wrong-password"}')
expect "bad password is 401" "$(status_of "$R")" "401"

R=$(api POST /auth/login '{"email":"nobody@nowhere.test","password":"whatever"}')
expect "unknown email is 401 (no enumeration)" "$(status_of "$R")" "401"

R=$(api POST /auth/login '{"email":"not-an-email","password":"x"}')
expect "invalid email is 422" "$(status_of "$R")" "422"

R=$(api POST /auth/login '{"email":"owner@clinic.test","password":"Password123"}')
expect "owner login" "$(status_of "$R")" "200"
OWNER_BODY=$(body_of "$R")
OWNER_ACCESS=$(jval "$OWNER_BODY" access_token)
OWNER_REFRESH=$(jval "$OWNER_BODY" refresh_token)
[ -n "$OWNER_ACCESS" ]  && pass "access token issued"  || fail "no access token"
[ -n "$OWNER_REFRESH" ] && pass "refresh token issued" || fail "no refresh token"
case "$OWNER_BODY" in
  *'"password"'*) fail "login response leaked the password hash" ;;
  *)              pass "password hash not in response" ;;
esac

R=$(api GET /me '' -H "Authorization: Bearer $OWNER_ACCESS")
expect "GET /me with token" "$(status_of "$R")" "200"

R=$(api GET /me)
expect "GET /me without token is 401" "$(status_of "$R")" "401"

R=$(api GET /me '' -H "Authorization: Bearer deadbeefdeadbeef")
expect "GET /me with garbage token is 401" "$(status_of "$R")" "401"

# A refresh token must not work as an access token.
R=$(api GET /me '' -H "Authorization: Bearer $OWNER_REFRESH")
expect "refresh token rejected on normal route" "$(status_of "$R")" "401"

# ---------------------------------------------------------------
echo
echo "[3] Tenant resolution (sec 10)"

R=$(api GET /organizations/current '' -H "Authorization: Bearer $OWNER_ACCESS")
expect "tenant resolved from session" "$(status_of "$R")" "200"
case "$(body_of "$R")" in
  *'Demo Dental'*) pass "correct organization returned" ;;
  *)               fail "unexpected organization body" "$(body_of "$R")" ;;
esac

# Asking for an organization the user is not a member of must be refused,
# even though the header is client-controlled.
R=$(api GET /organizations/current '' \
      -H "Authorization: Bearer $OWNER_ACCESS" -H 'X-Organization-Id: 99999')
expect "foreign X-Organization-Id is 403" "$(status_of "$R")" "403"

# ---------------------------------------------------------------
echo
echo "[4] RBAC (sec 11)"

R=$(api GET /organizations/current/members '' -H "Authorization: Bearer $OWNER_ACCESS")
expect "owner can list members" "$(status_of "$R")" "200"

R=$(api GET /organizations/current/roles '' -H "Authorization: Bearer $OWNER_ACCESS")
expect "owner can list roles" "$(status_of "$R")" "200"

R=$(api GET /audit-logs '' -H "Authorization: Bearer $OWNER_ACCESS")
expect "owner can read audit logs" "$(status_of "$R")" "200"

# Receptionist holds neither member.view nor audit.view.
R=$(api POST /auth/login '{"email":"reception@clinic.test","password":"Password123"}')
expect "receptionist login" "$(status_of "$R")" "200"
RECEPTION_ACCESS=$(jval "$(body_of "$R")" access_token)

R=$(api GET /organizations/current/members '' -H "Authorization: Bearer $RECEPTION_ACCESS")
expect "receptionist blocked from members" "$(status_of "$R")" "403"

R=$(api GET /audit-logs '' -H "Authorization: Bearer $RECEPTION_ACCESS")
expect "receptionist blocked from audit logs" "$(status_of "$R")" "403"

R=$(api PUT /organizations/current '{"name":"Hacked Clinic"}' \
      -H "Authorization: Bearer $RECEPTION_ACCESS")
expect "receptionist cannot rename the clinic" "$(status_of "$R")" "403"

# Doctor holds patient.* but not member.*
R=$(api POST /auth/login '{"email":"doctor@clinic.test","password":"Password123"}')
DOCTOR_ACCESS=$(jval "$(body_of "$R")" access_token)
R=$(api GET /organizations/current/members '' -H "Authorization: Bearer $DOCTOR_ACCESS")
expect "doctor blocked from members" "$(status_of "$R")" "403"

# ---------------------------------------------------------------
echo
reset_limits
echo "[5] Platform admin boundary (sec 21)"

R=$(api GET /platform/dashboard '' -H "Authorization: Bearer $OWNER_ACCESS")
expect "clinic owner blocked from platform panel" "$(status_of "$R")" "403"

R=$(api POST /auth/login '{"email":"admin@mediflow.test","password":"Password123"}')
expect "platform admin login" "$(status_of "$R")" "200"
ADMIN_ACCESS=$(jval "$(body_of "$R")" access_token)

R=$(api GET /platform/dashboard '' -H "Authorization: Bearer $ADMIN_ACCESS")
expect "platform admin can read dashboard" "$(status_of "$R")" "200"

R=$(api GET /platform/organizations '' -H "Authorization: Bearer $ADMIN_ACCESS")
expect "platform admin can list all organizations" "$(status_of "$R")" "200"

# ---------------------------------------------------------------
echo
reset_limits
echo "[6] Onboarding a second tenant (sec 22)"

STAMP=$(date +%s)
R=$(api POST /auth/register "{\"name\":\"Second Owner\",\"email\":\"owner$STAMP@second.test\",\"password\":\"Password123\"}")
expect "register new user" "$(status_of "$R")" "201"
SECOND_ACCESS=$(jval "$(body_of "$R")" access_token)

# A brand-new user belongs to nothing, so tenant routes must refuse.
R=$(api GET /organizations/current '' -H "Authorization: Bearer $SECOND_ACCESS")
expect "user with no membership blocked from tenant route" "$(status_of "$R")" "403"

R=$(api POST /organizations "{\"name\":\"Second Clinic $STAMP\",\"country_code\":\"PK\"}" \
      -H "Authorization: Bearer $SECOND_ACCESS")
expect "create second organization" "$(status_of "$R")" "201"
SECOND_ORG=$(jnum "$(body_of "$R")" id)

R=$(api GET /organizations/current '' -H "Authorization: Bearer $SECOND_ACCESS")
expect "creator became owner of it" "$(status_of "$R")" "200"

# THE isolation test: org A's owner must not reach org B.
R=$(api GET /organizations/current '' \
      -H "Authorization: Bearer $OWNER_ACCESS" -H "X-Organization-Id: $SECOND_ORG")
expect "cross-tenant access denied (clinic A -> clinic B)" "$(status_of "$R")" "403"

R=$(api GET /organizations/current '' \
      -H "Authorization: Bearer $SECOND_ACCESS" -H "X-Organization-Id: 1")
expect "cross-tenant access denied (clinic B -> clinic A)" "$(status_of "$R")" "403"

# ---------------------------------------------------------------
echo
echo "[7] Token rotation (sec 11)"

R=$(api POST /auth/refresh "{\"refresh_token\":\"$OWNER_REFRESH\"}")
expect "refresh returns a new pair" "$(status_of "$R")" "200"
NEW_ACCESS=$(jval "$(body_of "$R")" access_token)
[ -n "$NEW_ACCESS" ] && [ "$NEW_ACCESS" != "$OWNER_ACCESS" ] \
  && pass "new access token differs from old" \
  || fail "access token was not rotated"

# Rotation must invalidate the spent refresh token (replay detection).
R=$(api POST /auth/refresh "{\"refresh_token\":\"$OWNER_REFRESH\"}")
expect "replayed refresh token rejected" "$(status_of "$R")" "401"

# ...and the old access token dies with its refresh parent.
R=$(api GET /me '' -H "Authorization: Bearer $OWNER_ACCESS")
expect "old access token revoked after rotation" "$(status_of "$R")" "401"

R=$(api GET /me '' -H "Authorization: Bearer $NEW_ACCESS")
expect "rotated access token works" "$(status_of "$R")" "200"

# ---------------------------------------------------------------
echo
echo "[8] Sessions & logout (sec 11)"

R=$(api GET /auth/sessions '' -H "Authorization: Bearer $NEW_ACCESS")
expect "list active sessions" "$(status_of "$R")" "200"

R=$(api POST /auth/logout '' -H "Authorization: Bearer $NEW_ACCESS")
expect "logout" "$(status_of "$R")" "200"

R=$(api GET /me '' -H "Authorization: Bearer $NEW_ACCESS")
expect "token dead after logout" "$(status_of "$R")" "401"

# ---------------------------------------------------------------
echo
reset_limits
echo "[9] Audit trail (sec 16)"

R=$(api POST /auth/login '{"email":"owner@clinic.test","password":"Password123"}')
FRESH=$(jval "$(body_of "$R")" access_token)

R=$(api GET /audit-logs '' -H "Authorization: Bearer $FRESH")
BODY=$(body_of "$R")
case "$BODY" in
  *'"login"'*)  pass "login events recorded" ;;
  *)            fail "no login event in audit log" ;;
esac

R=$(api GET '/audit-logs?action=login_failed' '' -H "Authorization: Bearer $FRESH")
case "$(body_of "$R")" in
  *login_failed*) pass "failed logins recorded" ;;
  *)              fail "failed login not audited" ;;
esac

# The trail must never contain a password, even a wrong one.
R=$(api GET '/audit-logs?per_page=100' '' -H "Authorization: Bearer $FRESH")
case "$(body_of "$R")" in
  *wrong-password*) fail "audit log leaked a submitted password" ;;
  *)                pass "audit log contains no passwords" ;;
esac

# ---------------------------------------------------------------
echo
reset_limits
echo "[9b] Forgotten password (sec 11)"

FORGOT_EMAIL="forgot$(date +%s)@test.local"

reset_limits
R=$(api POST /auth/register \
      "{\"name\":\"Forgot Me\",\"email\":\"$FORGOT_EMAIL\",\"password\":\"Password123\"}")
expect "someone to lock out" "$(status_of "$R")" "201"

# Asking about an address nobody registered must look exactly like asking
# about one that exists, or the form becomes a way to enumerate accounts.
reset_limits
R=$(api POST /auth/forgot-password '{"email":"nobody-at-all@test.local"}')
expect "unknown email still answers 200" "$(status_of "$R")" "200"
UNKNOWN_MSG=$(jval "$(body_of "$R")" message)
case "$(body_of "$R")" in
  *'"code"'*) fail "a code was issued for an address with no account" ;;
  *)          pass "and issues no code for it" ;;
esac

reset_limits
R=$(api POST /auth/forgot-password "{\"email\":\"$FORGOT_EMAIL\"}")
expect "asking for a code" "$(status_of "$R")" "200"
KNOWN_MSG=$(jval "$(body_of "$R")" message)
[ "$KNOWN_MSG" = "$UNKNOWN_MSG" ] \
  && pass "the two answers are word for word the same" \
  || fail "the reply differs for a known address: '$KNOWN_MSG' vs '$UNKNOWN_MSG'"

CODE1=$(jval "$(body_of "$R")" code)
[ -n "$CODE1" ] \
  && pass "outside production the code comes back so the flow can be finished" \
  || fail "no code in the reply (is APP_ENV=production?)"

# The code goes out as an ordinary queued notification, on no organization.
NOTIF=$(sql "SELECT COUNT(*) FROM notifications
                      WHERE event = 'account.password_reset'
                        AND to_address = '$FORGOT_EMAIL'
                        AND channel = 'email'
                        AND organization_id IS NULL")
[ "${NOTIF:-0}" -ge 1 ] \
  && pass "the code was queued as an email, unattached to any clinic" \
  || fail "no queued email for the reset code"

# A wrong code is refused, and says nothing about what the right one is.
reset_limits
R=$(api POST /auth/reset-password \
      "{\"email\":\"$FORGOT_EMAIL\",\"code\":\"000000\",\"password\":\"BrandNew123\"}")
expect "a wrong code is refused" "$(status_of "$R")" "422"
case "$(body_of "$R")" in
  *"$CODE1"*) fail "the error leaked the real code" ;;
  *)          pass "and does not leak the real one" ;;
esac

# Asking again retires the first code: only the newest can work.
reset_limits
R=$(api POST /auth/forgot-password "{\"email\":\"$FORGOT_EMAIL\"}")
CODE2=$(jval "$(body_of "$R")" code)
[ -n "$CODE2" ] && pass "asking again issues another code" || fail "no second code"

reset_limits
R=$(api POST /auth/reset-password \
      "{\"email\":\"$FORGOT_EMAIL\",\"code\":\"$CODE1\",\"password\":\"BrandNew123\"}")
expect "the superseded code no longer works" "$(status_of "$R")" "422"

# A weak password is refused on its own terms — the code was right, so the
# message may say exactly what is wrong.
reset_limits
R=$(api POST /auth/reset-password \
      "{\"email\":\"$FORGOT_EMAIL\",\"code\":\"$CODE2\",\"password\":\"short\"}")
expect "a weak new password is refused" "$(status_of "$R")" "422"

reset_limits
R=$(api POST /auth/reset-password \
      "{\"email\":\"$FORGOT_EMAIL\",\"code\":\"$CODE2\",\"password\":\"nodigitshere\"}")
expect "and so is one with no digit" "$(status_of "$R")" "422"

# Sign in first, so there is a live session for the reset to end.
reset_limits
R=$(api POST /auth/login "{\"email\":\"$FORGOT_EMAIL\",\"password\":\"Password123\"}")
DOOMED=$(jval "$(body_of "$R")" access_token)
[ -n "$DOOMED" ] && pass "signed in on the old password" || fail "could not sign in"

reset_limits
R=$(api POST /auth/reset-password \
      "{\"email\":\"$FORGOT_EMAIL\",\"code\":\"$CODE2\",\"password\":\"BrandNew123\"}")
expect "the right code changes the password" "$(status_of "$R")" "200"

reset_limits
R=$(api POST /auth/login "{\"email\":\"$FORGOT_EMAIL\",\"password\":\"Password123\"}")
expect "the old password stops working" "$(status_of "$R")" "401"

reset_limits
R=$(api POST /auth/login "{\"email\":\"$FORGOT_EMAIL\",\"password\":\"BrandNew123\"}")
expect "the new one works" "$(status_of "$R")" "200"

R=$(api GET /me "" -H "Authorization: Bearer $DOOMED")
expect "and the session opened before it was signed out" "$(status_of "$R")" "401"

# Single use: the same code cannot be replayed.
reset_limits
R=$(api POST /auth/reset-password \
      "{\"email\":\"$FORGOT_EMAIL\",\"code\":\"$CODE2\",\"password\":\"ThirdOne123\"}")
expect "the used code is dead" "$(status_of "$R")" "422"

# Five wrong guesses and the code is thrown away, which is what keeps six
# digits from being brute-forceable.
reset_limits
R=$(api POST /auth/forgot-password "{\"email\":\"$FORGOT_EMAIL\"}")
CODE3=$(jval "$(body_of "$R")" code)
GUESS=0
while [ "$GUESS" -lt 5 ]; do
  api POST /auth/reset-password \
    "{\"email\":\"$FORGOT_EMAIL\",\"code\":\"999999\",\"password\":\"Guessing123\"}" >/dev/null
  GUESS=$((GUESS + 1))
done
reset_limits
R=$(api POST /auth/reset-password \
      "{\"email\":\"$FORGOT_EMAIL\",\"code\":\"$CODE3\",\"password\":\"Guessing123\"}")
expect "five wrong guesses kill the code, right one included" "$(status_of "$R")" "422"

# A locked-out account is exactly who needs this, so a reset must clear it.
LOCK_EMAIL="locked$(date +%s)@test.local"
reset_limits
R=$(api POST /auth/register \
      "{\"name\":\"Locked Out\",\"email\":\"$LOCK_EMAIL\",\"password\":\"Password123\"}")
expect "someone about to lock themselves out" "$(status_of "$R")" "201"

TRY=0
while [ "$TRY" -lt 6 ]; do
  reset_limits
  api POST /auth/login "{\"email\":\"$LOCK_EMAIL\",\"password\":\"WrongOne1\"}" >/dev/null
  TRY=$((TRY + 1))
done
reset_limits
R=$(api POST /auth/login "{\"email\":\"$LOCK_EMAIL\",\"password\":\"Password123\"}")
expect "the account is locked" "$(status_of "$R")" "403"

reset_limits
R=$(api POST /auth/forgot-password "{\"email\":\"$LOCK_EMAIL\"}")
LOCK_CODE=$(jval "$(body_of "$R")" code)
reset_limits
R=$(api POST /auth/reset-password \
      "{\"email\":\"$LOCK_EMAIL\",\"code\":\"$LOCK_CODE\",\"password\":\"Unlocked123\"}")
expect "a locked account can still reset" "$(status_of "$R")" "200"

reset_limits
R=$(api POST /auth/login "{\"email\":\"$LOCK_EMAIL\",\"password\":\"Unlocked123\"}")
expect "and the reset cleared the lockout" "$(status_of "$R")" "200"

# The trail records both halves (sec 16).
AUDITED=$(sql "SELECT COUNT(*) FROM audit_logs
                        WHERE action IN ('password_reset_requested','password_reset')")
[ "${AUDITED:-0}" -ge 2 ] \
  && pass "asking and resetting are both in the audit trail" \
  || fail "the reset is not audited"

# ---------------------------------------------------------------
echo
reset_limits
echo "[10] Validation & input handling"

R=$(api POST /auth/register '{}')
expect "missing required fields is 422" "$(status_of "$R")" "422"
case "$(body_of "$R")" in
  *required*) pass "422 says which fields are required" ;;
  *)          fail "422 did not name the missing fields" ;;
esac

R=$(api POST /auth/register '{"name":"X","email":"bad","password":"short"}')
expect "bad registration is 422" "$(status_of "$R")" "422"
case "$(body_of "$R")" in
  *'"fields"'*) pass "422 lists offending fields" ;;
  *)            fail "422 has no field detail" ;;
esac

R=$(api POST /auth/register '{"name":"Weak Pass","email":"weak'"$STAMP"'@test.test","password":"passwordonly"}')
expect "password with no digit rejected" "$(status_of "$R")" "422"

R=$(api POST /organizations '{"name":"No Country Clinic","country_code":"ZZ"}' \
      -H "Authorization: Bearer $FRESH")
expect "unconfigured country is 404" "$(status_of "$R")" "404"

# SQL injection attempt: must be treated as an ordinary (invalid) string.
R=$(api POST /auth/login '{"email":"admin@mediflow.test'"'"' OR 1=1 -- ","password":"x"}')
CODE=$(status_of "$R")
if [ "$CODE" = "422" ] || [ "$CODE" = "401" ]; then
  pass "SQL injection attempt safely rejected ($CODE)"
else
  fail "injection attempt returned $CODE" "$(body_of "$R")"
fi

# ---------------------------------------------------------------
echo
echo "============================="
printf 'passed: %d   failed: %d\n\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ] || exit 1
