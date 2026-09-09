#!/usr/bin/env bash
# §19 API structure: /api/v1 and the twelve endpoint groups it names.
set -uo pipefail
BASE="${BASE:-http://127.0.0.1:8000/api/v1}"
PASS=0; FAIL=0
step() { printf '\n\033[1m%s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m  %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m  %s  %s\n' "$1" "${2:-}"; }
want() { if [ "$2" = "$3" ]; then ok "$1 -> $2"; else bad "$1" "got [$2] want [$3]"; fi; }
tok()  { curl -s -X POST "$BASE/auth/login" -H 'Content-Type: application/json' \
           -d "{\"email\":\"$1\",\"password\":\"Password123\"}" \
         | grep -o '"access_token":"[^"]*"' | head -1 | cut -d'"' -f4; }

OTOK=$(tok owner@clinic.test)
[ -z "$OTOK" ] && { echo "cannot sign in"; exit 1; }
O=(-H "Authorization: Bearer $OTOK" -H 'X-Organization-Id: 1' -H 'Content-Type: application/json')
code() { curl -s -o /dev/null -w '%{http_code}' "${O[@]}" "$BASE$1"; }

step "1. The base route answers for itself"
C=$(curl -s -o /dev/null -w '%{http_code}' "$BASE"); want "GET /api/v1" "$C" "200"
BODY=$(curl -s "$BASE")
echo "$BODY" | grep -q '"version":"v1"' && ok "it names its version" || bad "no version"
echo "$BODY" | grep -q '"base":"\\/api\\/v1"\|"base":"/api/v1"' && ok "and its base path" || bad "no base path"
# It should list every group §19 asks for.
for g in auth patients doctors appointments encounters prescriptions labs billing payments insurance claims notifications; do
  echo "$BODY" | grep -q "\"group\":\"$g\"" && ok "index lists '$g'" || bad "index omits '$g'"
done
C=$(curl -s -o /dev/null -w '%{http_code}' "$BASE"); want "readable without a token" "$C" "200"

step "2. Every group of §19 is reachable"
want "/patients"            "$(code /patients)"            "200"
want "/doctors"             "$(code /doctors)"             "200"
want "/appointments"        "$(code /appointments)"        "200"
want "/encounters"          "$(code /encounters)"          "200"
want "/prescriptions"       "$(code /prescriptions)"       "200"
want "/labs/orders"         "$(code /labs/orders)"         "200"
want "/billing/invoices"    "$(code /billing/invoices)"    "200"
want "/billing/services"    "$(code /billing/services)"    "200"
want "/payments"            "$(code /payments)"            "200"
want "/insurance/providers" "$(code /insurance/providers)" "200"
want "/claims"              "$(code /claims)"              "200"
want "/notifications"       "$(code /notifications)"       "200"
# /auth is POST-only by design; 405 proves the group exists and is guarded.
want "/auth/login rejects GET" "$(code /auth/login)" "405"

step "3. The paths that shipped first still work"
want "/lab-orders" "$(code /lab-orders)" "200"
want "/invoices"   "$(code /invoices)"   "200"
want "/services"   "$(code /services)"   "200"

step "4. /prescriptions is a real collection, not a stub"
RX=$(curl -s "${O[@]}" "$BASE/prescriptions?status=issued")
echo "$RX" | grep -q '"prescription_no"' && ok "it returns prescriptions" || bad "no prescriptions came back"
echo "$RX" | grep -q '"patient_name"' && ok "with who each is for" || bad "no patient name"
echo "$RX" | grep -q '"item_count"'   && ok "and how many drugs are on it" || bad "no item count"
echo "$RX" | grep -q '"total"'        && ok "and it is paged" || bad "no pagination meta"
# The status filter has to actually filter.
DRAFTS=$(curl -s "${O[@]}" "$BASE/prescriptions?status=draft" | grep -o '"status":"issued"' | wc -l)
[ "$DRAFTS" -eq 0 ] && ok "the status filter excludes other statuses" || bad "filter leaked $DRAFTS issued rows"
want "a bad status is refused" "$(code '/prescriptions?status=nonsense')" "422"

step "5. /notifications is the signed-in person's own"
N=$(curl -s "${O[@]}" "$BASE/notifications")
echo "$N" | grep -q '"notifications"' && ok "the owner has an inbox" || bad "no inbox"
echo "$N" | grep -q '"unread"'        && ok "with an unread count" || bad "no unread count"
C=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/notifications")
want "and needs a token" "$C" "401"
# Staff notifications must not require a tenant: one person, one inbox.
C=$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $OTOK" "$BASE/notifications")
want "no X-Organization-Id needed" "$C" "200"
C=$(curl -s -o /dev/null -w '%{http_code}' -X POST "${O[@]}" "$BASE/notifications/read")
want "marking all read" "$C" "200"

step "6. Each group answers for one thing, not only for a list"
want "/labs/orders/{id}" "$(code /labs/orders/1)" "200"
ORDER=$(curl -s "${O[@]}" "$BASE/labs/orders/1")
echo "$ORDER" | grep -q '"results"'      && ok "an order carries its results" || bad "no results on the order"
echo "$ORDER" | grep -q '"patient_name"' && ok "and says who it is for"       || bad "no patient on the order"
want "a lab order that does not exist" "$(code /labs/orders/99999)" "404"

want "/labs/results" "$(code /labs/results)" "200"
RES=$(curl -s "${O[@]}" "$BASE/labs/results")
echo "$RES" | grep -q '"test_name"' && ok "results list by test"   || bad "no results came back"
echo "$RES" | grep -q '"order_no"'  && ok "with the order each is from" || bad "no order on the result"
# The flag filter is the reason this endpoint exists.
OTHERS=$(curl -s "${O[@]}" "$BASE/labs/results?flag=critical" | grep -o '"flag":"normal"' | wc -l)
[ "$OTHERS" -eq 0 ] && ok "?flag=critical excludes normal results" || bad "flag filter leaked $OTHERS normal rows"
want "a bad flag is refused" "$(code '/labs/results?flag=purple')" "422"

want "/payments/{id}" "$(code /payments/1)" "200"
PAY=$(curl -s "${O[@]}" "$BASE/payments/1")
echo "$PAY" | grep -q '"receipt_no"'     && ok "a payment is a receipt"       || bad "no receipt number"
echo "$PAY" | grep -q '"invoice_no"'     && ok "against a named invoice"      || bad "no invoice on the payment"
echo "$PAY" | grep -q '"refunded_total"' && ok "and says what came back"      || bad "no refunded total"
want "a payment that does not exist" "$(code /payments/99999)" "404"

step "7. Unknown routes still 404 rather than guessing"
want "an invented group" "$(code /teleportation)" "404"

echo
echo "========================================="
echo "passed: $PASS   failed: $FAIL"
[ "$FAIL" -eq 0 ]
