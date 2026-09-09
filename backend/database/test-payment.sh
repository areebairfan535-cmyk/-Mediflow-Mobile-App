#!/usr/bin/env bash
# §7 online payment, end to end, against the stub gateway.
#
# The stub stands in for PayPal so the parts that are ours get tested on every
# run: that the amount comes off the invoice, that a reference settles once,
# that one patient cannot pay from another's balance.
set -uo pipefail
BASE="${BASE:-http://127.0.0.1:8000/api/v1}"
MYSQL="${MYSQL:-/c/xampp/mysql/bin/mysql.exe}"
DB="${DB:-mediflow}"
PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m  %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m  %s  %s\n' "$1" "${2:-}"; }
want() { if [ "$2" = "$3" ]; then ok "$1 -> $2"; else bad "$1" "got [$2] want [$3]"; fi; }
sql()  { "$MYSQL" -u root "$DB" -N -e "$1"; }
jget() { echo "$1" | grep -o "\"$2\":\"[^\"]*\"" | head -1 | cut -d'"' -f4; }

LOGIN=$(curl -s -X POST "$BASE/auth/login" -H 'Content-Type: application/json' \
  -d '{"email":"patient@demo.test","password":"Password123"}')
TOK=$(echo "$LOGIN" | grep -o '"access_token":"[^"]*"' | head -1 | cut -d'"' -f4)
[ -z "$TOK" ] && { echo "cannot sign in: $(echo "$LOGIN" | head -c 200)"; exit 1; }
A=(-H "Authorization: Bearer $TOK" -H 'Content-Type: application/json')

echo
echo "[1] The app is told whether paying is possible"
S=$(curl -s "${A[@]}" "$BASE/patient/payments/status")
echo "$S" | grep -q '"gateway":"stub"'   && ok "gateway reported as stub"  || bad "wrong gateway" "$S"
echo "$S" | grep -q '"configured":true'  && ok "reported as configured"    || bad "not configured" "$S"
echo "$S" | grep -q '"mode"'             && ok "mode is reported"          || bad "no mode" "$S"

# An unpaid invoice belonging to this patient.
PID=$(sql "SELECT p.id FROM patients p JOIN users u ON u.id=p.user_id WHERE u.email='patient@demo.test'")
INV=$(sql "SELECT id FROM invoices WHERE patient_id=$PID AND status IN ('issued','partially_paid','overdue') AND grand_total > paid_total ORDER BY id LIMIT 1")
[ -z "$INV" ] && { echo "no unpaid invoice for the demo patient — run database/seed_billing.php"; exit 1; }
DUE=$(sql "SELECT ROUND(grand_total - paid_total, 2) FROM invoices WHERE id=$INV")
echo "  (invoice $INV, $DUE outstanding)"

echo
echo "[2] Opening a payment writes nothing"
BEFORE=$(sql "SELECT COUNT(*) FROM payments WHERE invoice_id=$INV")
R=$(curl -s -X POST "${A[@]}" "$BASE/patient/invoices/$INV/pay")
REF=$(jget "$R" reference)
[ -n "$REF" ] && ok "a reference came back" || bad "no reference" "$R"
echo "$R" | grep -q '"approval_url"' && ok "and somewhere to send the payer" || bad "no approval url" "$R"
AMT=$(jget "$R" amount)
want "the amount is the invoice's, not ours" "$AMT" "$DUE"
AFTER=$(sql "SELECT COUNT(*) FROM payments WHERE invoice_id=$INV")
want "no payment row yet" "$AFTER" "$BEFORE"

echo
echo "[3] Confirming settles it, once"
R=$(curl -s -X POST "${A[@]}" "$BASE/patient/payments/confirm" -d "{\"reference\":\"$REF\"}")
echo "$R" | grep -q '"receipt_no"' && ok "a receipt was issued" || bad "no receipt" "$R"
PAID=$(sql "SELECT ROUND(paid_total,2) FROM invoices WHERE id=$INV")
want "the invoice is settled" "$(sql "SELECT ROUND(grand_total - paid_total, 2) FROM invoices WHERE id=$INV")" "0.00"
N=$(sql "SELECT COUNT(*) FROM payments WHERE invoice_id=$INV AND gateway='stub'")
want "exactly one payment row" "$N" "1"
METHOD=$(sql "SELECT method FROM payments WHERE invoice_id=$INV AND gateway='stub'")
want "recorded as an online payment" "$METHOD" "online"

echo
echo "[4] The same reference cannot be banked twice"
curl -s -o /dev/null -X POST "${A[@]}" "$BASE/patient/payments/confirm" -d "{\"reference\":\"$REF\"}"
curl -s -o /dev/null -X POST "${A[@]}" "$BASE/patient/payments/confirm" -d "{\"reference\":\"$REF\"}"
N=$(sql "SELECT COUNT(*) FROM payments WHERE invoice_id=$INV AND gateway='stub'")
want "still one payment row after two replays" "$N" "1"

echo
echo "[5] A settled invoice cannot be paid again"
C=$(curl -s -o /dev/null -w '%{http_code}' -X POST "${A[@]}" "$BASE/patient/invoices/$INV/pay")
want "paying a settled invoice" "$C" "409"

echo
echo "[6] Somebody else's invoice is not payable"
OTHER=$(sql "SELECT id FROM invoices WHERE patient_id <> $PID AND status <> 'draft' ORDER BY id LIMIT 1")
if [ -n "$OTHER" ]; then
  C=$(curl -s -o /dev/null -w '%{http_code}' -X POST "${A[@]}" "$BASE/patient/invoices/$OTHER/pay")
  want "another patient's invoice (id $OTHER)" "$C" "404"
else
  ok "no other patient's invoice to try"
fi

echo
echo "[7] A made-up reference settles nothing"
C=$(curl -s -o /dev/null -w '%{http_code}' -X POST "${A[@]}" "$BASE/patient/payments/confirm" -d '{"reference":"STUB-not-a-real-one"}')
want "a forged reference" "$C" "503"
C=$(curl -s -o /dev/null -w '%{http_code}' -X POST "${A[@]}" "$BASE/patient/payments/confirm" -d '{"reference":""}')
want "an empty reference" "$C" "422"

echo
echo "[8] None of it is reachable without signing in"
C=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/patient/payments/status")
want "status without a token" "$C" "401"
C=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' "$BASE/patient/invoices/$INV/pay")
want "pay without a token" "$C" "401"

echo
echo "========================================="
echo "passed: $PASS   failed: $FAIL"
[ "$FAIL" -eq 0 ]
