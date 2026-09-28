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
echo "[3a] The dashboard counts the live ones (sec 21)"

# §21 asks for organization STATUS and for ACTIVE doctors and patients. On
# seeded data nothing is ever suspended or disabled, so active and total are
# always equal and a filter could be missing entirely without anyone noticing.
# So make one of each inactive and watch the numbers move.
dash_num() { printf '%s' "$1" | grep -o "\"$2\":[0-9]\+" | head -1 | sed "s/.*://"; }

R=$(api GET /platform/dashboard '' "${AAUTH[@]}")
B=$(body_of "$R")
ORGS0=$(dash_num "$B" active_organizations)
TOTAL0=$(dash_num "$B" total_organizations)
DOCS0=$(dash_num "$B" doctors)
DOCTOTAL0=$(dash_num "$B" doctors_total)
PATS0=$(dash_num "$B" patients)

# The suite's own clinic, and the newest doctor and patient — never the demo
# ones, and every change is put back below.
DOCUSER=$(sql "SELECT u.id FROM doctors d JOIN users u ON u.id = d.user_id
               WHERE u.status = 'active' ORDER BY d.id DESC LIMIT 1" | tr -d '\r')
PAT=$(sql "SELECT id FROM patients WHERE status = 'active' ORDER BY id DESC LIMIT 1" | tr -d '\r')

api PUT "/platform/organizations/$NEW_ORG/status" '{"status":"suspended"}' "${AAUTH[@]}" > /dev/null
sql "UPDATE users SET status='disabled' WHERE id=$DOCUSER" > /dev/null
sql "UPDATE patients SET status='inactive' WHERE id=$PAT"  > /dev/null

R=$(api GET /platform/dashboard '' "${AAUTH[@]}")
B=$(body_of "$R")

expect "a suspended clinic leaves the active count" "$(dash_num "$B" active_organizations)" "$((ORGS0 - 1))"
expect "but stays in the total"                     "$(dash_num "$B" total_organizations)"  "$TOTAL0"
expect "a disabled account leaves active doctors"   "$(dash_num "$B" doctors)"              "$((DOCS0 - 1))"
# The doctors row outlives the person leaving — that is why both are reported.
expect "while the doctors on record stand"          "$(dash_num "$B" doctors_total)"        "$DOCTOTAL0"
expect "an inactive patient leaves the count"       "$(dash_num "$B" patients)"             "$((PATS0 - 1))"

# Put the clinic back exactly as it was found.
api PUT "/platform/organizations/$NEW_ORG/status" '{"status":"active"}' "${AAUTH[@]}" > /dev/null
sql "UPDATE users SET status='active' WHERE id=$DOCUSER" > /dev/null
sql "UPDATE patients SET status='active' WHERE id=$PAT"  > /dev/null

R=$(api GET /platform/dashboard '' "${AAUTH[@]}")
B=$(body_of "$R")
[ "$(dash_num "$B" active_organizations)" = "$ORGS0" ] \
  && [ "$(dash_num "$B" doctors)" = "$DOCS0" ] \
  && [ "$(dash_num "$B" patients)" = "$PATS0" ] \
  && pass "and everything is put back" \
  || fail "the suite left something suspended or disabled"

# The three money figures are one subtraction apart, which is the first thing
# anybody does with them. They were once taken over three different sets.
MB=$(printf '%s' "$B" | grep -o '"billed_total":"[0-9.]*"'      | sed 's/.*:"//; s/"//')
MC=$(printf '%s' "$B" | grep -o '"collected_total":"[0-9.]*"'   | sed 's/.*:"//; s/"//')
MO=$(printf '%s' "$B" | grep -o '"outstanding_total":"[0-9.]*"' | sed 's/.*:"//; s/"//')
DIFF=$(awk -v a="$MB" -v b="$MC" 'BEGIN { printf "%.2f", a - b }')
expect "billed minus collected is outstanding" "$DIFF" "$MO"

# ---------------------------------------------------------------
echo
echo "[3b] One clinic's doctors and patients (sec 21)"

# sec 21 asks a platform admin to look after doctor and patient accounts, and
# a count of members does not do that. The detail endpoint says who.
R=$(api GET "/platform/organizations/1" '' "${AAUTH[@]}")
expect "the clinic's people load" "$(status_of "$R")" "200"
B=$(body_of "$R")

# A role slug does not say what somebody IS: this clinic's doctor holds
# `solo_practitioner`, and its owner is a dentist holding `org_owner`. Anyone
# grouping people by reading the slug gets both wrong, so the rows carry the
# fact — a doctors row and a patients row — instead.
case "$B" in
  *'"doctor_id"'*)  pass "members say who is a doctor" ;;
  *)                fail "no doctor_id on the member rows" ;;
esac
case "$B" in
  *'"patient_id"'*) pass "and who is a patient" ;;
  *)                fail "no patient_id on the member rows" ;;
esac

# The count has to match the doctors table, not the number of doctor-ish role
# names — those disagree, which is the whole point.
DOCROWS=$(sql "SELECT COUNT(*) FROM doctors d
   JOIN organization_users ou ON ou.user_id = d.user_id AND ou.organization_id = d.organization_id
  WHERE d.organization_id = 1" | tr -d '\r')
# [0-9]\+ , not [0-9]* — a star matches the empty string, so "doctor_id":null
# counted as a doctor and every member looked like one.
SEEN=$(printf '%s' "$B" | grep -o '"doctor_id":[0-9]\+' | wc -l | tr -d ' ')
expect "every doctor on the record is named" "$SEEN" "$DOCROWS"

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
echo
echo "[6] Bookings across every clinic (sec 21)"

# Answering a booking is the one cross-tenant WRITE in the API, so what is
# asserted below is not the 200 — it is what the clinic hears afterwards. The
# write goes through that clinic's own AppointmentService, or a patient is left
# waiting on a yes nobody told them about.

R=$(api GET '/platform/appointments?status=booked&per_page=5' '' "${AAUTH[@]}")
expect "admin lists bookings across clinics" "$(status_of "$R")" "200"
case "$(body_of "$R")" in
  *'"organization_name"'*) pass "every row names the clinic it belongs to" ;;
  *)                       fail "the cross-tenant list does not say which clinic a booking is in" ;;
esac

R=$(api GET /platform/appointments '' "${OAUTH[@]}")
expect "clinic owner refused the cross-tenant list" "$(status_of "$R")" "403"

APPT=$(sql "SELECT id FROM appointments WHERE status='booked' ORDER BY id DESC LIMIT 1" | tr -d '\r' | head -1)

if [ -z "${APPT:-}" ]; then
  fail "no booking left in 'booked' to answer" "demo data has drifted; php database/seed_today.php"
else
  APPT_ORG=$(sql "SELECT organization_id FROM appointments WHERE id=$APPT" | tr -d '\r' | head -1)

  # What to put back afterwards. A suite that confirms a booking and walks away
  # has spent one of the rows the next run needs.
  WAS_BY=$(sql "SELECT IFNULL(updated_by,'NULL') FROM appointments WHERE id=$APPT" | tr -d '\r' | head -1)
  WAS_AT=$(sql "SELECT updated_at FROM appointments WHERE id=$APPT" | tr -d '\r' | head -1)
  MAX_N=$(sql "SELECT IFNULL(MAX(id),0) FROM notifications" | tr -d '\r' | head -1)
  MAX_A=$(sql "SELECT IFNULL(MAX(id),0) FROM audit_logs"     | tr -d '\r' | head -1)

  R=$(api PUT "/platform/appointments/$APPT/status" '{"status":"completed"}' "${AAUTH[@]}")
  expect "a status the platform may not set" "$(status_of "$R")" "422"

  R=$(api PUT "/platform/appointments/$APPT/status" '{"status":"on_hold"}' "${AAUTH[@]}")
  expect "a hold with no reason for the patient" "$(status_of "$R")" "422"

  R=$(api PUT "/platform/appointments/99999999/status" '{"status":"confirmed"}' "${AAUTH[@]}")
  expect "a booking that does not exist" "$(status_of "$R")" "404"

  R=$(api PUT "/platform/appointments/$APPT/status" '{"status":"confirmed"}' "${OAUTH[@]}")
  expect "clinic owner cannot answer through the platform route" "$(status_of "$R")" "403"

  R=$(api PUT "/platform/appointments/$APPT/status" '{"status":"confirmed"}' "${AAUTH[@]}")
  expect "the platform confirms a clinic's booking" "$(status_of "$R")" "200"
  case "$(body_of "$R")" in
    *'"status":"confirmed"'*) pass "and the booking comes back confirmed" ;;
    *)                        fail "the answer did not change the booking" ;;
  esac

  # The point of routing the write through the clinic: the same people are told
  # as when the front desk clicks.
  TOLD=$(sql "SELECT COUNT(*) FROM notifications
               WHERE id>$MAX_N AND subject_type='appointment' AND subject_id=$APPT
                 AND event='appointment.confirmed'" | tr -d '\r' | head -1)
  [ "${TOLD:-0}" -gt 0 ] && pass "the patient is told the booking is a promise now" \
                         || fail "the patient was never told the booking was confirmed"

  TOLD=$(sql "SELECT COUNT(*) FROM notifications
               WHERE id>$MAX_N AND subject_type='appointment' AND subject_id=$APPT
                 AND event='appointment.confirmed.owner'" | tr -d '\r' | head -1)
  [ "${TOLD:-0}" -gt 0 ] && pass "and the clinic's owner hears that it was answered" \
                         || fail "the clinic was not told the platform answered for it"

  # Filed under the clinic, not the platform: a trail the clinic itself reads is
  # the only place "someone else answered my booking" can be seen.
  FILED=$(sql "SELECT COUNT(*) FROM audit_logs
                WHERE id>$MAX_A AND resource_type='appointment' AND resource_id=$APPT
                  AND organization_id=$APPT_ORG" | tr -d '\r' | head -1)
  [ "${FILED:-0}" -gt 0 ] && pass "the answer is filed in the clinic's own trail" \
                          || fail "the platform answered a booking with no clinic-side record"

  # Put the booking back on the pile. There is no transition from confirmed back
  # to booked by design, so this goes through SQL, and the rows the answer wrote
  # go with it.
  sql "UPDATE appointments SET status='booked', updated_by=$WAS_BY, updated_at='$WAS_AT' WHERE id=$APPT" >/dev/null 2>&1
  sql "DELETE FROM notifications WHERE id>$MAX_N" >/dev/null 2>&1
  sql "DELETE FROM audit_logs    WHERE id>$MAX_A" >/dev/null 2>&1
  BACK=$(sql "SELECT status FROM appointments WHERE id=$APPT" | tr -d '\r' | head -1)
  [ "${BACK:-x}" = "booked" ] && pass "and the booking is left waiting as it was found" \
                              || fail "the booking was left $BACK"
fi

# ---------------------------------------------------------------
# Put the shop back as it was found: the plan this run invented is retired so
# no clinic is ever offered it, and the market it opened is closed again.
if [ -n "${NEW_PLAN:-}" ]; then
  R=$(api PUT "/platform/plans/$NEW_PLAN" '{"is_active":false}' "${AAUTH[@]}")
  expect "the test plan is retired afterwards" "$(status_of "$R")" "200"

  # Retiring it is not the same as putting it back. A retired plan is hidden
  # from sign-up but still sits in the platform admin's price list, and that
  # list had grown to thirty entries where §22 names four — one per run of
  # this suite and its neighbour, invisible to customers and squarely in the
  # face of the person whose job is pricing.
  #
  # There is no DELETE on /platform/plans by design: a plan with live
  # subscriptions must not vanish under them. So this goes through SQL, and
  # moves anything sitting on it back to Free first, because the foreign key
  # on subscriptions.plan_id is RESTRICT.
  FREE_ID=$(sql "SELECT id FROM plans WHERE slug='free' LIMIT 1" | tr -d '\r' | head -1)
  [ -n "$FREE_ID" ] && sql "UPDATE subscriptions SET plan_id=$FREE_ID WHERE plan_id=$NEW_PLAN" >/dev/null 2>&1
  sql "DELETE FROM plans WHERE id=$NEW_PLAN" >/dev/null 2>&1
  GONE=$(sql "SELECT COUNT(*) FROM plans WHERE id=$NEW_PLAN" | tr -d '\r' | head -1)
  [ "${GONE:-1}" = "0" ] && pass "and taken back out of the catalogue entirely" \
                         || fail "the test plan is still in the price list"
fi

# §22 names four tiers. After this suite there should be four.
STRAY=$(sql "SELECT COUNT(*) FROM plans WHERE slug NOT IN ('free','starter','professional','enterprise')" | tr -d '\r' | head -1)
[ "${STRAY:-x}" = "0" ] && pass "the price list is the four tiers and nothing else" \
                        || fail "$STRAY test plan(s) left in the catalogue"

# ---------------------------------------------------------------
echo
echo "============================================="
printf 'passed: %d   failed: %d\n\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ] || exit 1
