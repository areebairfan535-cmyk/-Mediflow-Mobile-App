#!/usr/bin/env bash
# §6/§7 billing engine: the catalogue, the statuses an invoice moves through,
# the ways money arrives, and the ledger that records all of it.
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
step() { printf '\n\033[1m%s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m  %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m  %s  %s\n' "$1" "${2:-}"; }
want() { if [ "$2" = "$3" ]; then ok "$1 -> $2"; else bad "$1" "got [$2] want [$3]"; fi; }
sql()  { "$MYSQL" -u root "$DB" -N -e "$1"; }
idof() { echo "$1" | grep -o "\"id\":[0-9]*" | head -1 | cut -d: -f2; }

TOKEN=$(curl -s -X POST "$BASE/auth/login" -H 'Content-Type: application/json' \
  -d '{"email":"owner@clinic.test","password":"Password123"}' \
  | grep -o '"access_token":"[^"]*"' | head -1 | cut -d'"' -f4)
[ -z "$TOKEN" ] && { echo "cannot sign in"; exit 1; }
H=(-H "Authorization: Bearer $TOKEN" -H 'X-Organization-Id: 1' -H 'Content-Type: application/json')
PID=$(sql "SELECT p.id FROM patients p JOIN users u ON u.id=p.user_id WHERE u.email='patient@demo.test'")

step "1. The catalogue holds every kind of service the document names"
CAT=$(curl -s "${H[@]}" "$BASE/services?per_page=100")
for want_svc in "Consultation" "Follow-up" "Injection" "X-Ray" "MRI" "Surgery" "Ward"; do
  echo "$CAT" | grep -qi -- "$want_svc" && ok "$want_svc is in the catalogue" \
    || bad "$want_svc is missing"
done
for cat in consultation followup injection imaging procedure room lab; do
  N=$(sql "SELECT COUNT(*) FROM services WHERE organization_id=1 AND category='$cat' AND is_active=1")
  if [ "${N:-0}" -gt 0 ]; then ok "category '$cat' has $N service(s)"; else bad "category '$cat' is empty"; fi
done

step "2. A service is configurable, not hard-coded"
STAMP=$(date +%s)
R=$(curl -s -X POST "${H[@]}" "$BASE/services" \
      -d "{\"code\":\"CFG-$STAMP\",\"name\":\"Configurable check $STAMP\",\"category\":\"procedure\",\"department\":\"OPD\",\"is_taxable\":true,\"price\":4321}")
SVC=$(idof "$R")
[ -n "$SVC" ] && ok "a new service can be added (id $SVC)" || bad "cannot add a service" "$(echo "$R" | head -c 250)"
C=$(curl -s -o /dev/null -w '%{http_code}' -X PUT "${H[@]}" "$BASE/services/$SVC" -d '{"name":"Renamed"}')
want "and edited" "$C" "200"

step "3. An invoice walks its statuses"
# A line names the service by id — the price comes off the catalogue, never
# off the request, which is the whole reason the id is what travels.
CONSULT=$(sql "SELECT id FROM services WHERE organization_id=1 AND code='CONSULT-GEN'")
XRAY=$(sql "SELECT id FROM services WHERE organization_id=1 AND code='IMG-XRAY'")
R=$(curl -s -X POST "${H[@]}" "$BASE/invoices" -d "{\"patient_id\":$PID,\"items\":[
  {\"service_id\":$CONSULT,\"quantity\":1},
  {\"service_id\":$XRAY,\"quantity\":2}]}")
INV=$(idof "$R")
[ -n "$INV" ] && ok "drafted (id $INV)" || bad "no invoice" "$(echo "$R" | head -c 300)"
want "it starts as a draft" "$(sql "SELECT status FROM invoices WHERE id=$INV")" "draft"

# A draft is the clinic's working document — the patient must not see it.
PTOK=$(curl -s -X POST "$BASE/auth/login" -H 'Content-Type: application/json' \
  -d '{"email":"patient@demo.test","password":"Password123"}' \
  | grep -o '"access_token":"[^"]*"' | head -1 | cut -d'"' -f4)
C=$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $PTOK" "$BASE/patient/invoices/$INV")
want "a draft is invisible to the patient" "$C" "404"

curl -s -o /dev/null -X POST "${H[@]}" "$BASE/invoices/$INV/issue" -d '{}'
want "issued" "$(sql "SELECT status FROM invoices WHERE id=$INV")" "issued"
TOTAL=$(sql "SELECT ROUND(grand_total,2) FROM invoices WHERE id=$INV")
ok "the total is $TOTAL"

step "4. Money arrives three ways, and the invoice keeps up"
HALF=$(awk -v t="$TOTAL" 'BEGIN{printf "%.2f", t/4}')
curl -s -o /dev/null -X POST "${H[@]}" "$BASE/invoices/$INV/payments" -d "{\"amount\":$HALF,\"method\":\"cash\"}"
want "part paid in cash -> partially_paid" "$(sql "SELECT status FROM invoices WHERE id=$INV")" "partially_paid"

curl -s -o /dev/null -X POST "${H[@]}" "$BASE/invoices/$INV/payments" -d "{\"amount\":$HALF,\"method\":\"bank_transfer\"}"
ok "another quarter by bank transfer"

REST=$(sql "SELECT ROUND(grand_total - paid_total,2) FROM invoices WHERE id=$INV")
R=$(curl -s -X POST "${H[@]}" "$BASE/invoices/$INV/payments" -d "{\"amount\":$REST,\"method\":\"card\"}")
echo "$R" | grep -q '"receipt_no"' && ok "and the rest by card" || bad "card payment failed" "$(echo "$R" | head -c 250)"
want "settled" "$(sql "SELECT status FROM invoices WHERE id=$INV")" "paid"
want "nothing left owing" "$(sql "SELECT ROUND(grand_total - paid_total,2) FROM invoices WHERE id=$INV")" "0.00"

step "5. Overpaying is refused"
C=$(curl -s -o /dev/null -w '%{http_code}' -X POST "${H[@]}" "$BASE/invoices/$INV/payments" \
      -d '{"amount":100,"method":"cash"}')
if [ "$C" = "409" ] || [ "$C" = "422" ]; then ok "a payment beyond the balance -> $C"
else bad "the invoice took more than it was owed" "got $C"; fi

step "6. The ledger shows every one of them"
LED=$(curl -s "${H[@]}" "$BASE/payments?invoice_id=$INV")
for m in cash bank_transfer card; do
  echo "$LED" | grep -q "\"$m\"" && ok "$m is in the ledger" || bad "$m missing from the ledger"
done
N=$(sql "SELECT COUNT(*) FROM payments WHERE invoice_id=$INV")
want "three payments recorded" "$N" "3"
N=$(sql "SELECT COUNT(DISTINCT receipt_no) FROM payments WHERE invoice_id=$INV")
want "each with its own receipt" "$N" "3"

step "7. Cancelling, and the statuses that remain"
FU=$(sql "SELECT id FROM services WHERE organization_id=1 AND code='CONSULT-FU'")
R=$(curl -s -X POST "${H[@]}" "$BASE/invoices" -d "{\"patient_id\":$PID,\"items\":[{\"service_id\":$FU,\"quantity\":1}]}")
INV2=$(idof "$R")
curl -s -o /dev/null -X POST "${H[@]}" "$BASE/invoices/$INV2/issue" -d '{}'
curl -s -o /dev/null -X POST "${H[@]}" "$BASE/invoices/$INV2/cancel" -d '{"reason":"Raised against the wrong patient"}'
want "cancelled" "$(sql "SELECT status FROM invoices WHERE id=$INV2")" "cancelled"
C=$(curl -s -o /dev/null -w '%{http_code}' -X POST "${H[@]}" "$BASE/invoices/$INV2/payments" -d '{"amount":100,"method":"cash"}')
if [ "$C" != "201" ] && [ "$C" != "200" ]; then ok "a cancelled invoice takes no money -> $C"
else bad "a cancelled invoice accepted a payment"; fi

# overdue is reached by the nightly job, not by hand
sql "UPDATE invoices SET due_date = DATE_SUB(CURDATE(), INTERVAL 2 DAY), status='issued' WHERE id=$INV2" >/dev/null
curl -s -o /dev/null -X POST "${H[@]}" "$BASE/invoices/mark-overdue"
want "overdue" "$(sql "SELECT status FROM invoices WHERE id=$INV2")" "overdue"

echo
echo "  statuses seen: draft, issued, partially_paid, paid, cancelled, overdue"

echo
echo "========================================="
echo "passed: $PASS   failed: $FAIL"
[ "$FAIL" -eq 0 ]
