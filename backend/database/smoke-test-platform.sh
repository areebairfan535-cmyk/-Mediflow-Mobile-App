#!/usr/bin/env bash
# Super Admin Panel — §21, plus the §22 price list and §23 market config.
#
#   bash database/smoke-test-platform.sh
#
# The point of this suite is the boundary, not the CRUD: these are the only
# cross-tenant routes in the API, so what a clinic owner CANNOT reach here
# matters more than what an admin can.

set -uo pipefail

BASE="${BASE:-http://127.0.0.1:8000/api/v1}"
MYSQL="${MYSQL:-/c/xampp/mysql/bin/mysql.exe}"
DB="${DB:-mediflow}"
PASS=0
FAIL=0

pass() { PASS=$((PASS + 1)); printf '  \033[32mPASS\033[0m  %s\n' "$1"; }
fail() { FAIL=$((FAIL + 1)); printf '  \033[31mFAIL\033[0m  %s\n' "$1"; [ -n "${2:-}" ] && printf '        %s\n' "$2"; }
expect() { if [ "$2" = "$3" ]; then pass "$1 -> $2"; else fail "$1 -> got $2, want $3" "${4:-}"; fi; }

reset_limits() { [ -x "$MYSQL" ] && "$MYSQL" -u root "$DB" -e "TRUNCATE TABLE rate_limits;" 2>/dev/null; }
sql() { "$MYSQL" -u root "$DB" -N -e "$1"; }

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
jval() { printf '%s' "$1" | grep -o "\"$2\":\"[^\"]*\"" | head -1 | sed "s/.*\"$2\":\"//; s/\"$//"; }
jnum() { printf '%s' "$1" | grep -o "\"$2\":[0-9]*" | head -1 | sed "s/.*://"; }

reset_limits

# Each run adds a plan and a market. Left behind, they turn the price list a
# clinic actually chooses from into a list of test rows — so anything from an
# earlier run that nobody subscribed to goes first.
[ -x "$MYSQL" ] && "$MYSQL" -u root "$DB" -e \
  "DELETE FROM plans
    WHERE slug LIKE 'trial%'
      AND id NOT IN (SELECT plan_id FROM subscriptions);" 2>/dev/null

echo
echo "MediFlow — super admin panel (sec 21, 22, 23)"
echo "============================================="

# ---------------------------------------------------------------
echo
echo "[setup] sign in"

R=$(api POST /auth/login '{"email":"admin@mediflow.test","password":"Password123"}')
expect "platform admin login" "$(status_of "$R")" "200"
ADMIN=$(jval "$(body_of "$R")" access_token)
AAUTH=(-H "Authorization: Bearer $ADMIN")

R=$(api POST /auth/login '{"email":"owner@clinic.test","password":"Password123"}')
OWNER=$(jval "$(body_of "$R")" access_token)
OAUTH=(-H "Authorization: Bearer $OWNER" -H "X-Organization-Id: 1")

STAMP=$(date +%s)

# ---------------------------------------------------------------
echo
echo "[1] The boundary (sec 21)"

for path in /platform/dashboard /platform/organizations /platform/plans /platform/countries; do
  R=$(api GET "$path" '' "${AAUTH[@]}")
  expect "admin reads $path" "$(status_of "$R")" "200"

  R=$(api GET "$path" '' "${OAUTH[@]}")
  expect "clinic owner refused $path" "$(status_of "$R")" "403"
done

R=$(api POST /platform/plans '{"slug":"sneaky","name":"Sneaky"}' "${OAUTH[@]}")
expect "clinic owner cannot invent a plan" "$(status_of "$R")" "403"

R=$(api GET /platform/dashboard)
expect "no token at all" "$(status_of "$R")" "401"

# ---------------------------------------------------------------
echo
echo "[2] Plans are editable, and say who is on them (sec 22)"

R=$(api GET /platform/plans '' "${AAUTH[@]}")
B=$(body_of "$R")
case "$B" in
  *'"organizations"'*) pass "each plan reports how many clinics hold it" ;;
  *)                   fail "no adoption count on plans" ;;
esac
case "$B" in
  *'"features":{'*) pass "features come back decoded, not as a JSON string" ;;
  *)                fail "features not decoded" ;;
esac

R=$(api POST /platform/plans \
    "{\"slug\":\"trial$STAMP\",\"name\":\"Trial $STAMP\",\"price_monthly\":9,\"currency_code\":\"USD\",\"max_doctors\":1,\"max_staff\":2,\"max_patients\":50}" \
    "${AAUTH[@]}")
expect "create a plan" "$(status_of "$R")" "201"
NEW_PLAN=$(jnum "$(body_of "$R")" id)

R=$(api POST /platform/plans "{\"slug\":\"trial$STAMP\",\"name\":\"Duplicate\"}" "${AAUTH[@]}")
expect "duplicate slug refused" "$(status_of "$R")" "409"

R=$(api PUT "/platform/plans/$NEW_PLAN" '{"name":"Trial renamed","price_monthly":19}' "${AAUTH[@]}")
expect "edit the plan" "$(status_of "$R")" "200"
case "$(body_of "$R")" in
  *'"name":"Trial renamed"'*) pass "the rename stuck" ;;
  *)                          fail "rename not applied" ;;
esac

# Limits left out of an update must not be wiped.
KEPT=$(sql "SELECT max_patients FROM plans WHERE id=$NEW_PLAN")
[ "$KEPT" = "50" ] && pass "limits absent from the request were left alone" \
                   || fail "an unrelated limit changed during a rename (got $KEPT)"

# ...but an explicit null means unlimited, which is a different request.
R=$(api PUT "/platform/plans/$NEW_PLAN" '{"max_patients":null}' "${AAUTH[@]}")
expect "explicit null accepted" "$(status_of "$R")" "200"
UNLIMITED=$(sql "SELECT max_patients IS NULL FROM plans WHERE id=$NEW_PLAN")
[ "$UNLIMITED" = "1" ] && pass "null set the limit to unlimited" || fail "null did not clear the limit"

R=$(api PUT "/platform/plans/$NEW_PLAN" "{\"slug\":\"renamed$STAMP\"}" "${AAUTH[@]}")
SLUG_NOW=$(sql "SELECT slug FROM plans WHERE id=$NEW_PLAN")
[ "$SLUG_NOW" = "trial$STAMP" ] && pass "the slug is immutable — clients key off it" \
                                || fail "the slug was renamed under existing clients"

R=$(api PUT /platform/plans/999999 '{"name":"Nope"}' "${AAUTH[@]}")
expect "unknown plan is 404" "$(status_of "$R")" "404"

# ---------------------------------------------------------------
echo
echo "[3] Moving a clinic between plans (sec 21, sec 22)"

# A new clinic, so the demo one is never left on a test plan.
R=$(api POST /auth/register "{\"name\":\"Plat Tester\",\"email\":\"plat$STAMP@test.local\",\"password\":\"Password123\"}")
NEW=$(jval "$(body_of "$R")" access_token)
R=$(api POST /organizations "{\"name\":\"Platform Clinic $STAMP\",\"country_code\":\"PK\"}" \
    -H "Authorization: Bearer $NEW")
NEW_ORG=$(jnum "$(body_of "$R")" id)
[ -n "$NEW_ORG" ] && pass "test clinic created (#$NEW_ORG)" || fail "could not create a test clinic"

PRO=$(sql "SELECT id FROM plans WHERE slug='professional'")

R=$(api PUT "/platform/organizations/$NEW_ORG/plan" "{\"plan_id\":$PRO}" "${AAUTH[@]}")
expect "admin moves the clinic to Professional" "$(status_of "$R")" "200"
ON=$(sql "SELECT p.slug FROM subscriptions s JOIN plans p ON p.id=s.plan_id WHERE s.organization_id=$NEW_ORG")
[ "$ON" = "professional" ] && pass "the subscription followed" || fail "plan not applied (on $ON)"

R=$(api PUT "/platform/organizations/$NEW_ORG/plan" "{\"plan_id\":$NEW_PLAN}" "${OAUTH[@]}")
expect "a clinic owner cannot move anyone" "$(status_of "$R")" "403"

R=$(api PUT "/platform/organizations/999999/plan" "{\"plan_id\":$PRO}" "${AAUTH[@]}")
expect "unknown organization is 404" "$(status_of "$R")" "404"

# The demo clinic has more staff than the tiny plan allows: platform staff get
# the same refusal a clinic owner would, rather than a quiet override.
R=$(api PUT "/platform/organizations/1/plan" "{\"plan_id\":$NEW_PLAN}" "${AAUTH[@]}")
expect "downgrade below usage refused for admins too" "$(status_of "$R")" "422"
DEMO=$(sql "SELECT p.slug FROM subscriptions s JOIN plans p ON p.id=s.plan_id WHERE s.organization_id=1")
[ "$DEMO" = "professional" ] && pass "the demo clinic kept its plan" || fail "demo clinic changed to $DEMO"

# ---------------------------------------------------------------
echo
echo "[4] Countries are configuration, not code (sec 23)"

R=$(api GET /platform/countries '' "${AAUTH[@]}")
B=$(body_of "$R")
for code in PK US GB AE; do
  case "$B" in
    *"\"$code\""*) pass "$code is configured" ;;
    *)             fail "$code missing from countries" ;;
  esac
done

R=$(api POST /platform/countries \
    '{"code":"SG","name":"Singapore","currency_code":"SGD","currency_symbol":"S$","timezone":"Asia/Singapore","default_tax_rate":0.09,"invoice_prefix":"INV"}' \
    "${AAUTH[@]}")
CODE=$(status_of "$R")
if [ "$CODE" = "201" ]; then
  pass "add a market -> 201"
elif [ "$CODE" = "409" ]; then
  pass "market already configured -> 409 (re-run)"
else
  fail "add a market -> got $CODE" "$(body_of "$R")"
fi

SG=$(sql "SELECT id FROM countries WHERE code='SG'")

# The last section of this suite closes the market deliberately. Re-open it so
# a second run starts from the same place as the first.
sql "UPDATE countries SET is_active = 1, default_tax_rate = 0.09 WHERE code = 'SG'" >/dev/null

R=$(api POST /platform/countries '{"code":"SG","name":"Duplicate","currency_code":"SGD","timezone":"Asia/Singapore"}' "${AAUTH[@]}")
expect "duplicate country refused" "$(status_of "$R")" "409"

R=$(api POST /platform/countries '{"code":"XX","name":"Bad tax","currency_code":"USD","timezone":"UTC","default_tax_rate":5}' "${AAUTH[@]}")
expect "a tax rate above 100% refused" "$(status_of "$R")" "422"

# A brand-new clinic can be opened there straight away — that is the whole
# point of §23: a market is a row, not a release.
R=$(api POST /auth/register "{\"name\":\"SG Owner\",\"email\":\"sg$STAMP@test.local\",\"password\":\"Password123\"}")
SGTOK=$(jval "$(body_of "$R")" access_token)
R=$(api POST /organizations "{\"name\":\"Singapore Clinic $STAMP\",\"country_code\":\"SG\"}" \
    -H "Authorization: Bearer $SGTOK")
expect "a clinic opens in the new market" "$(status_of "$R")" "201"
SG_ORG=$(jnum "$(body_of "$R")" id)

# The clinic's own currency column stays NULL — it INHERITS the market until
# it overrides. So the check is on the resolved settings, not the column.
case "$(body_of "$R")" in
  *'"currency_code":"SGD"'*) pass "it resolves to the market's currency (SGD)" ;;
  *)                         fail "new clinic did not inherit SGD" "$(body_of "$R")" ;;
esac

SUB_CUR=$(sql "SELECT currency_code FROM subscriptions WHERE organization_id=$SG_ORG")
[ "$SUB_CUR" = "SGD" ] && pass "and its subscription is billed in SGD" \
                       || fail "subscription currency is $SUB_CUR, want SGD"

R=$(api PUT "/platform/countries/$SG" '{"code":"SG","name":"Singapore","currency_code":"SGD","timezone":"Asia/Singapore","default_tax_rate":0.10,"is_active":false}' "${AAUTH[@]}")
expect "edit the market" "$(status_of "$R")" "200"
case "$(body_of "$R")" in
  *"already issued keep the rate"*)
    pass "the response is explicit about what the edit does and does not touch" ;;
  *) fail "no note about the blast radius of the edit" ;;
esac

# A clinic that never set its own tax follows the market — that is the point of
# §23 and the correct behaviour for a rate change. What must NOT move is a rate
# already printed on an invoice.
TAX=$(sql "SELECT default_tax_rate FROM countries WHERE id=$SG")
case "$TAX" in
  0.1000) pass "the market's tax rate changed" ;;
  *)      fail "tax rate is $TAX, want 0.1000" ;;
esac

FROZEN=$(sql "SELECT COUNT(*) FROM invoices WHERE status <> 'draft' AND tax_total IS NULL")
[ "$FROZEN" = "0" ] && pass "issued invoices carry their own tax, so none was re-rated" \
                    || fail "$FROZEN issued invoices have no stored tax"

# Closing a market stops new sign-ups in it.
R=$(api POST /organizations "{\"name\":\"Too Late $STAMP\",\"country_code\":\"SG\"}" \
    -H "Authorization: Bearer $SGTOK")
expect "a closed market refuses new clinics" "$(status_of "$R")" "404"

# ---------------------------------------------------------------
echo
echo "[5] Audit (sec 16)"

# Plans and markets belong to no clinic, so they are NOT in a tenant's trail —
# they live in the platform one. Both halves are asserted, because "audited
# somewhere nobody can read" is the failure mode worth catching.
for kind in plan country; do
  R=$(api GET "/platform/audit-logs?resource_type=$kind" '' "${AAUTH[@]}")
  expect "platform trail readable ($kind)" "$(status_of "$R")" "200"
  case "$(body_of "$R")" in
    *"\"resource_type\":\"$kind\""*) pass "$kind changes are audited" ;;
    *)                               fail "$kind changes missing from the platform trail" ;;
  esac

  R=$(api GET "/audit-logs?resource_type=$kind" '' "${OAUTH[@]}")
  case "$(body_of "$R")" in
    *"\"resource_type\":\"$kind\""*) fail "platform-only rows leaked into a clinic's trail" ;;
    *)                               pass "and stay out of a clinic's own trail" ;;
  esac
done

R=$(api GET /platform/audit-logs '' "${OAUTH[@]}")
expect "a clinic owner cannot read the platform trail" "$(status_of "$R")" "403"

# A plan change made FOR a clinic is that clinic's business, so it lands in
# their trail even though platform staff pressed the button.
R=$(api GET "/platform/audit-logs?organization_id=$NEW_ORG&resource_type=subscription" '' "${AAUTH[@]}")
case "$(body_of "$R")" in
  *'"resource_type":"subscription"'*) pass "the clinic's plan change is filed against the clinic" ;;
  *)                                  fail "plan change not attributed to the organization" ;;
esac

# ---------------------------------------------------------------
# Put the shop back as it was found: the plan this run invented is retired so
# no clinic is ever offered it, and the market it opened is closed again.
if [ -n "${NEW_PLAN:-}" ]; then
  R=$(api PUT "/platform/plans/$NEW_PLAN" '{"is_active":false}' "${AAUTH[@]}")
  expect "the test plan is retired afterwards" "$(status_of "$R")" "200"
fi

# ---------------------------------------------------------------
echo
echo "============================================="
printf 'passed: %d   failed: %d\n\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ] || exit 1
