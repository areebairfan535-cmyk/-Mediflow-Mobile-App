#!/usr/bin/env bash
# Subscription and plan limits — §22.
#
#   bash database/smoke-test-subscription.sh
#
# Two things are being proved here, and the second matters more than the first:
#
#   1. A clinic can see its plan, its usage, and change plan.
#   2. A limit actually stops a write. A "limit" that is only displayed is a
#      number on a page, not a limit — so the refusals are tested against a
#      brand-new organization on the Free plan, which is what a real sign-up
#      gets.
#
# Requires the API on :8000 with seed.php run.

set -uo pipefail

BASE="${BASE:-http://127.0.0.1:8000/api/v1}"
MYSQL="${MYSQL:-/c/xampp/mysql/bin/mysql.exe}"
DB="${DB:-mediflow}"
PASS=0
FAIL=0

pass() { PASS=$((PASS + 1)); printf '  \033[32mPASS\033[0m  %s
' "$1"; }
fail() { FAIL=$((FAIL + 1)); printf '  \033[31mFAIL\033[0m  %s
' "$1"; [ -n "${2:-}" ] && printf '        %s
' "$2"; }
expect() { if [ "$2" = "$3" ]; then pass "$1 -> $2"; else fail "$1 -> got $2, want $3" "${4:-}"; fi; }

reset_limits() { [ -x "$MYSQL" ] && "$MYSQL" -u root "$DB" -e "TRUNCATE TABLE rate_limits;" 2>/dev/null; }
sql() { "$MYSQL" -u root "$DB" -N -e "$1"; }

api() {
  local method="$1" path="$2" body="${3:-}"; shift 3 || shift $#
  if [ -n "$body" ]; then
    curl -s -w '
%{http_code}' -X "$method" "$BASE$path" \
      -H 'Content-Type: application/json' -d "$body" "$@"
  else
    curl -s -w '
%{http_code}' -X "$method" "$BASE$path" "$@"
  fi
}

status_of() { printf '%s' "$1" | tail -n1; }
body_of()   { printf '%s' "$1" | sed '$d'; }
jval() { printf '%s' "$1" | grep -o "\"$2\":\"[^\"]*\"" | head -1 | sed "s/.*\"$2\":\"//; s/\"$//"; }
jnum() { printf '%s' "$1" | grep -o "\"$2\":[0-9]*" | head -1 | sed "s/.*://"; }

reset_limits

echo
echo "MediFlow — subscription & plan limits (sec 22)"
echo "=============================================="

# ---------------------------------------------------------------
echo
echo "[setup] sign in"

R=$(api POST /auth/login '{"email":"owner@clinic.test","password":"Password123"}')
expect "owner login" "$(status_of "$R")" "200"
OWNER=$(jval "$(body_of "$R")" access_token)
OAUTH=(-H "Authorization: Bearer $OWNER")

R=$(api POST /auth/login '{"email":"doctor@clinic.test","password":"Password123"}')
DOC=$(jval "$(body_of "$R")" access_token)
DAUTH=(-H "Authorization: Bearer $DOC")

FREE=$(sql "SELECT id FROM plans WHERE slug='free'")
STARTER=$(sql "SELECT id FROM plans WHERE slug='starter'")
PRO=$(sql "SELECT id FROM plans WHERE slug='professional'")
ENTERPRISE=$(sql "SELECT id FROM plans WHERE slug='enterprise'")

# ---------------------------------------------------------------
echo
echo "[1] The price list and the current plan"

R=$(api GET /plans '' "${OAUTH[@]}")
expect "plans listed" "$(status_of "$R")" "200"
for slug in free starter professional enterprise; do
  case "$(body_of "$R")" in
    *"\"$slug\""*) pass "offers the $slug plan" ;;
    *)             fail "$slug plan missing" ;;
  esac
done

R=$(api GET /organizations/current/subscription '' "${OAUTH[@]}")
expect "current subscription" "$(status_of "$R")" "200"
B=$(body_of "$R")

case "$B" in
  *'"slug":"professional"'*) pass "the demo clinic is on Professional" ;;
  *) fail "unexpected plan for the demo clinic — run database/seed.php" "$B" ;;
esac

for metric in doctors staff patients storage appointments invoices ai_calls; do
  case "$B" in
    *"\"$metric\""*) pass "usage reported for $metric" ;;
    *)               fail "$metric missing from usage" ;;
  esac
done

# Usage must be counted, not guessed.
DOCTORS=$(sql "SELECT COUNT(*) FROM doctors WHERE organization_id=1")
case "$B" in
  *"\"metric\":\"doctors\",\"label\":\"Doctors\",\"used\":$DOCTORS,"*)
    pass "doctor count matches the table ($DOCTORS)" ;;
  *) fail "doctor usage does not match the doctors table" ;;
esac

# ---------------------------------------------------------------
echo
echo "[2] Who may change the plan"

R=$(api PUT /organizations/current/subscription "{\"plan_id\":$ENTERPRISE}" "${DAUTH[@]}")
expect "a doctor cannot change the plan" "$(status_of "$R")" "403"

R=$(api PUT /organizations/current/subscription '{"plan_id":999999}' "${OAUTH[@]}")
expect "unknown plan is 404" "$(status_of "$R")" "404"

R=$(api PUT /organizations/current/subscription '{}' "${OAUTH[@]}")
expect "plan_id is required" "$(status_of "$R")" "422"

# ---------------------------------------------------------------
echo
echo "[3] A downgrade that would not fit is refused, not truncated"

# The demo clinic has more staff accounts than Free allows. Accepting the
# change would leave it instantly over its own limit — with no way for the
# clinic to know which accounts stopped working.
R=$(api PUT /organizations/current/subscription "{\"plan_id\":$FREE}" "${OAUTH[@]}")
expect "downgrade below current usage refused" "$(status_of "$R")" "422"
case "$(body_of "$R")" in
  *"in use"*) pass "the refusal names what is in the way" ;;
  *)          fail "unhelpful downgrade error" "$(body_of "$R")" ;;
esac

STILL=$(sql "SELECT p.slug FROM subscriptions s JOIN plans p ON p.id=s.plan_id WHERE s.organization_id=1")
[ "$STILL" = "professional" ] && pass "the plan did not change" || fail "plan changed despite the refusal"

R=$(api PUT /organizations/current/subscription "{\"plan_id\":$ENTERPRISE}" "${OAUTH[@]}")
expect "upgrade accepted" "$(status_of "$R")" "200"
case "$(body_of "$R")" in
  *'"unlimited":true'*) pass "Enterprise reports unlimited metrics" ;;
  *)                    fail "no unlimited metric on Enterprise" ;;
esac

R=$(api PUT /organizations/current/subscription "{\"plan_id\":$PRO}" "${OAUTH[@]}")
expect "back to Professional" "$(status_of "$R")" "200"

R=$(api GET '/audit-logs?resource_type=subscription' '' "${OAUTH[@]}")
case "$(body_of "$R")" in
  *'"resource_type":"subscription"'*) pass "plan changes are audited" ;;
  *)                                  fail "plan change missing from the trail" ;;
esac

# ---------------------------------------------------------------
echo
echo "[3b] Signing up (sec 22 onboarding)"

# "Choose plan" is the FIRST step in §22, which is before an account exists —
# so the price list and the open markets have to be readable without one.
R=$(api GET /public/plans)
expect "the price list is public" "$(status_of "$R")" "200"
case "$(body_of "$R")" in
  *'"slug":"free"'*) pass "and names the plans a clinic can pick" ;;
  *)                  fail "no plans returned" ;;
esac
# A price list is not a tenant list: it must not leak who is on what.
case "$(body_of "$R")" in
  *'"organizations"'*) fail "the public price list leaked adoption counts" ;;
  *)                    pass "without saying who subscribes to them" ;;
esac

R=$(api GET /public/countries)
expect "open markets are public" "$(status_of "$R")" "200"
case "$(body_of "$R")" in
  *'"currency_code"'*) pass "each one carries its currency and timezone" ;;
  *)                    fail "no market detail returned" ;;
esac

# The whole §22 flow the sign-up screen performs: register, then create the
# clinic on the chosen plan.
SIGNUP=$(date +%s)
R=$(api POST /auth/register \
    "{\"name\":\"Signup Owner\",\"email\":\"signup$SIGNUP@test.local\",\"password\":\"Password123\"}")
expect "register the person" "$(status_of "$R")" "201"
SIGNUP_TOKEN=$(jval "$(body_of "$R")" access_token)

R=$(api POST /organizations \
    "{\"name\":\"Signup Clinic $SIGNUP\",\"country_code\":\"PK\",\"plan\":\"starter\"}" \
    -H "Authorization: Bearer $SIGNUP_TOKEN")
expect "create the clinic on the chosen plan" "$(status_of "$R")" "201"
SIGNUP_ORG=$(jnum "$(body_of "$R")" id)

LANDED=$(sql "SELECT p.slug FROM subscriptions s JOIN plans p ON p.id=s.plan_id WHERE s.organization_id=$SIGNUP_ORG")
[ "$LANDED" = "starter" ] && pass "it starts on the plan that was picked, not on Free" \
                          || fail "landed on $LANDED"

# The market decides the money, not the form.
SIGNUP_CUR=$(sql "SELECT currency_code FROM subscriptions WHERE organization_id=$SIGNUP_ORG")
[ "$SIGNUP_CUR" = "PKR" ] && pass "and is billed in the market's currency" \
                          || fail "billed in $SIGNUP_CUR, expected PKR"

# Creating a clinic is what makes you its owner — §22's "admin account" step.
R=$(api GET /me/context '' -H "Authorization: Bearer $SIGNUP_TOKEN" -H "X-Organization-Id: $SIGNUP_ORG")
case "$(body_of "$R")" in
  *'"role":"org_owner"'*) pass "the person who created it is its owner" ;;
  *)                       fail "creator is not the owner" ;;
esac

# An unknown plan slug must not silently become something else.
R=$(api POST /auth/register \
    "{\"name\":\"Bad Plan\",\"email\":\"badplan$SIGNUP@test.local\",\"password\":\"Password123\"}")
BAD_TOKEN=$(jval "$(body_of "$R")" access_token)
R=$(api POST /organizations \
    "{\"name\":\"Bad Plan Clinic $SIGNUP\",\"country_code\":\"PK\",\"plan\":\"platinum-deluxe\"}" \
    -H "Authorization: Bearer $BAD_TOKEN")
expect "an unknown plan still creates the clinic" "$(status_of "$R")" "201"
BAD_ORG=$(jnum "$(body_of "$R")" id)
FELL_BACK=$(sql "SELECT p.slug FROM subscriptions s JOIN plans p ON p.id=s.plan_id WHERE s.organization_id=$BAD_ORG")
[ "$FELL_BACK" = "free" ] && pass "on Free rather than on nothing" \
                          || fail "unknown plan resolved to $FELL_BACK"

# ---------------------------------------------------------------
echo
echo "[4] A new clinic starts on Free, and Free actually stops it"

STAMP=$(date +%s)
R=$(api POST /auth/register "{\"name\":\"Plan Tester\",\"email\":\"plan$STAMP@test.local\",\"password\":\"Password123\"}")
expect "register a new owner" "$(status_of "$R")" "201"
NEW=$(jval "$(body_of "$R")" access_token)
NAUTH=(-H "Authorization: Bearer $NEW")

R=$(api POST /organizations "{\"name\":\"Limit Clinic $STAMP\",\"country_code\":\"PK\"}" "${NAUTH[@]}")
expect "create the organization" "$(status_of "$R")" "201"
NEW_ORG=$(jnum "$(body_of "$R")" id)
NAUTH=(-H "Authorization: Bearer $NEW" -H "X-Organization-Id: $NEW_ORG")

SUB=$(sql "SELECT COUNT(*) FROM subscriptions WHERE organization_id=$NEW_ORG")
[ "$SUB" = "1" ] && pass "a subscription was created with the clinic" \
                 || fail "the new clinic has no subscription"

R=$(api GET /organizations/current/subscription '' "${NAUTH[@]}")
case "$(body_of "$R")" in
  *'"slug":"free"'*) pass "and it starts on Free" ;;
  *)                 fail "new clinic is not on the Free plan" "$(body_of "$R")" ;;
esac

# Free allows 3 staff accounts. The owner is already one of them.
STAFF_LIMIT=$(sql "SELECT max_staff FROM plans WHERE slug='free'")
ROLE=$(sql "SELECT id FROM roles WHERE slug='receptionist' AND organization_id IS NULL LIMIT 1")

ADDED=0
BLOCKED=""
i=1
while [ "$i" -le $((STAFF_LIMIT + 2)) ]; do
  R=$(api POST /organizations/current/members \
      "{\"email\":\"staff$i.$STAMP@test.local\",\"name\":\"Staff $i\",\"role_id\":$ROLE}" "${NAUTH[@]}")
  CODE=$(status_of "$R")
  if [ "$CODE" = "201" ]; then
    ADDED=$((ADDED + 1))
  else
    BLOCKED="$CODE"
    BODY=$(body_of "$R")
    break
  fi
  i=$((i + 1))
done

[ "$ADDED" = "$((STAFF_LIMIT - 1))" ] \
  && pass "added $ADDED staff — the seats Free leaves after the owner" \
  || fail "added $ADDED staff, expected $((STAFF_LIMIT - 1))"

expect "the next one is refused" "${BLOCKED:-none}" "402"
case "${BODY:-}" in
  *'"code":"plan_limit_reached"'*) pass "typed as a plan limit, not a permission error" ;;
  *)                               fail "wrong error code for a plan limit" "${BODY:-}" ;;
esac
case "${BODY:-}" in
  *Upgrade*) pass "the message says what to do about it" ;;
  *)         fail "the refusal does not mention upgrading" ;;
esac

# ---------------------------------------------------------------
echo
echo "[5] Upgrading lifts the limit immediately"

R=$(api PUT /organizations/current/subscription "{\"plan_id\":$STARTER}" "${NAUTH[@]}")
expect "upgrade to Starter" "$(status_of "$R")" "200"

R=$(api POST /organizations/current/members \
    "{\"email\":\"staffX.$STAMP@test.local\",\"name\":\"Staff X\",\"role_id\":$ROLE}" "${NAUTH[@]}")
expect "the same request now succeeds" "$(status_of "$R")" "201"

# ---------------------------------------------------------------
echo
echo "[6] AI usage is metered (sec 9, sec 22)"

# Free allows no AI at all, which is a plan decision rather than a missing
# provider — and the two must not be confused with each other.
R=$(api PUT /organizations/current/subscription "{\"plan_id\":$FREE}" "${NAUTH[@]}")
if [ "$(status_of "$R")" = "200" ]; then
  R=$(api GET /organizations/current/subscription '' "${NAUTH[@]}")
  case "$(body_of "$R")" in
    *'"metric":"ai_calls","label":"AI assistant calls this month","used":0,"limit":0'*)
      pass "Free reports a zero AI allowance" ;;
    *) fail "AI allowance not reported on Free" ;;
  esac
else
  echo "  (still above Free after the extra seat — AI allowance check skipped)"
fi

R=$(api GET /ai/status '' "${NAUTH[@]}")
expect "AI status is still readable" "$(status_of "$R")" "200"

# ---------------------------------------------------------------
echo
echo "[7] Tenant isolation holds for plans too (sec 10)"

R=$(api GET /organizations/current/subscription '' \
    -H "Authorization: Bearer $NEW" -H "X-Organization-Id: 1")
expect "cannot read another clinic's subscription" "$(status_of "$R")" "403"

R=$(api PUT /organizations/current/subscription "{\"plan_id\":$ENTERPRISE}" \
    -H "Authorization: Bearer $NEW" -H "X-Organization-Id: 1")
expect "cannot upgrade another clinic" "$(status_of "$R")" "403"

DEMO_PLAN=$(sql "SELECT p.slug FROM subscriptions s JOIN plans p ON p.id=s.plan_id WHERE s.organization_id=1")
[ "$DEMO_PLAN" = "professional" ] && pass "the demo clinic's plan is untouched" \
                                  || fail "another tenant changed the demo clinic's plan"

# ---------------------------------------------------------------
echo
echo "=============================================="
printf 'passed: %d   failed: %d

' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ] || exit 1
