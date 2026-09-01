#!/usr/bin/env bash
# Phase 3 end-to-end test — billing, payments, refunds, reports.
#
#   bash database/smoke-test-billing.sh
#
# Money assertions are exact. A billing suite that only checks HTTP codes
# would pass while charging the wrong amount.

set -uo pipefail

BASE="${BASE:-http://127.0.0.1:8000/api/v1}"
MYSQL="${MYSQL:-/c/xampp/mysql/bin/mysql.exe}"
PHP="${PHP:-/c/xampp/php/php.exe}"
DB="${DB:-mediflow}"
PASS=0
FAIL=0

pass() { PASS=$((PASS + 1)); printf '  \033[32mPASS\033[0m  %s\n' "$1"; }
fail() { FAIL=$((FAIL + 1)); printf '  \033[31mFAIL\033[0m  %s\n' "$1"; [ -n "${2:-}" ] && printf '        %s\n' "$2"; }
expect() { if [ "$2" = "$3" ]; then pass "$1 -> $2"; else fail "$1 -> got $2, want $3" "${4:-}"; fi; }
eq()     { if [ "$2" = "$3" ]; then pass "$1 = $2"; else fail "$1: got $2, want $3"; fi; }

reset_limits() { [ -x "$MYSQL" ] && "$MYSQL" -u root "$DB" -e "TRUNCATE TABLE rate_limits;" 2>/dev/null; }

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
jval()  { printf '%s' "$1" | grep -o "\"$2\":\"[^\"]*\"" | head -1 | sed "s/.*\"$2\":\"//; s/\"$//"; }
jnum()  { printf '%s' "$1" | grep -o "\"$2\":[0-9]*" | head -1 | sed "s/.*://"; }
# Money comes back as a JSON string like "4680.00"
jmoney() { printf '%s' "$1" | grep -o "\"$2\":\"[0-9.-]*\"" | head -1 | sed "s/.*\":\"//; s/\"$//"; }

reset_limits

echo
echo "MediFlow Phase 3 — billing smoke test"
echo "====================================="

# ---------------------------------------------------------------
echo
echo "[setup] sign in"

R=$(api POST /auth/login '{"email":"owner@clinic.test","password":"Password123"}')
expect "owner login" "$(status_of "$R")" "200"
OWNER=$(jval "$(body_of "$R")" access_token)
OAUTH=(-H "Authorization: Bearer $OWNER")

R=$(api POST /auth/login '{"email":"reception@clinic.test","password":"Password123"}')
RECEPTION=$(jval "$(body_of "$R")" access_token)
RAUTH=(-H "Authorization: Bearer $RECEPTION")

R=$(api POST /auth/login '{"email":"billing@clinic.test","password":"Password123"}')
BILLING=$(jval "$(body_of "$R")" access_token)
BAUTH=(-H "Authorization: Bearer $BILLING")

R=$(api POST /auth/login '{"email":"doctor@clinic.test","password":"Password123"}')
DOC=$(jval "$(body_of "$R")" access_token)
DAUTH=(-H "Authorization: Bearer $DOC")

R=$(api GET '/patients?per_page=1' '' "${OAUTH[@]}")
PATIENT=$(jnum "$(body_of "$R")" id)

# ---------------------------------------------------------------
echo
echo "[1] Service catalogue (sec 6)"

R=$(api GET /services '' "${OAUTH[@]}")
expect "list catalogue" "$(status_of "$R")" "200"
case "$(body_of "$R")" in
  *CONSULT-GEN*) pass "catalogue seeded" ;;
  *)             fail "CONSULT-GEN missing — run seed_billing.php" ;;
esac

# Grab two services with known prices.
CONSULT=$("$MYSQL" -u root "$DB" -N -e "SELECT id FROM services WHERE code='CONSULT-GEN' LIMIT 1")
SCALING=$("$MYSQL" -u root "$DB" -N -e "SELECT id FROM services WHERE code='DENT-SCALE' LIMIT 1")

R=$(api POST /services '{"code":"CONSULT-GEN","name":"Duplicate"}' "${OAUTH[@]}")
expect "duplicate service code refused" "$(status_of "$R")" "409"

R=$(api GET /services '' "${RAUTH[@]}")
expect "receptionist can read the catalogue" "$(status_of "$R")" "200"

R=$(api POST /services '{"code":"X-TEST","name":"Test"}' "${RAUTH[@]}")
expect "receptionist cannot edit the catalogue" "$(status_of "$R")" "403"

# Setting the catalogue up is §22's "configure services" step, so it has to be
# doable through the API the clinic screen uses — not only from a seeder.
STAMP=$(date +%s)
R=$(api POST /services \
    "{\"code\":\"UI-$STAMP\",\"name\":\"Catalogue test\",\"category\":\"procedure\",\"price\":1200,\"currency_code\":\"PKR\",\"is_taxable\":true}" \
    "${OAUTH[@]}")
expect "add a service with its opening price" "$(status_of "$R")" "201"
NEWSVC=$(jnum "$(body_of "$R")" id)

R=$(api GET "/services?search=UI-$STAMP" '' "${OAUTH[@]}")
case "$(body_of "$R")" in
  *"\"price\":\"1200.00\""*) pass "it comes back priced" ;;
  *)                         fail "the opening price was not applied" ;;
esac

# A service with no price must not become an invoice with a guessed one.
R=$(api POST /services "{\"code\":\"NOPRICE-$STAMP\",\"name\":\"Unpriced\",\"category\":\"other\"}" "${OAUTH[@]}")
UNPRICED=$(jnum "$(body_of "$R")" id)
R=$(api POST /invoices "{\"patient_id\":$PATIENT,\"items\":[{\"service_id\":$UNPRICED,\"quantity\":1}]}" "${OAUTH[@]}")
expect "an unpriced service cannot be invoiced" "$(status_of "$R")" "422"

# A new price supersedes the old one instead of overwriting it — an invoice
# raised yesterday has to keep yesterday's figure.
R=$(api POST "/services/$NEWSVC/prices" '{"price":1500,"currency_code":"PKR","max_discount_pct":10}' "${OAUTH[@]}")
expect "raise the price" "$(status_of "$R")" "201"

HISTORY=$("$MYSQL" -u root "$DB" -N -e "SELECT COUNT(*) FROM service_prices WHERE service_id=$NEWSVC")
[ "$HISTORY" = "2" ] && pass "the old price is kept, not overwritten" \
                     || fail "price history has $HISTORY row(s), expected 2"

OPEN_ROWS=$("$MYSQL" -u root "$DB" -N -e "SELECT COUNT(*) FROM service_prices WHERE service_id=$NEWSVC AND effective_to IS NULL")
[ "$OPEN_ROWS" = "1" ] && pass "exactly one price is in force" \
                       || fail "$OPEN_ROWS prices are open at once"

# A closed period must not end before it began.
INVERTED=$("$MYSQL" -u root "$DB" -N -e "SELECT COUNT(*) FROM service_prices WHERE service_id=$NEWSVC AND effective_to < effective_from")
[ "$INVERTED" = "0" ] && pass "no price period ends before it starts" \
                      || fail "$INVERTED price row(s) have an inverted date range"

R=$(api GET "/services?search=UI-$STAMP" '' "${OAUTH[@]}")
case "$(body_of "$R")" in
  *"\"price\":\"1500.00\""*) pass "the catalogue now quotes the new price" ;;
  *)                         fail "still quoting the old price" ;;
esac

R=$(api PUT "/services/$UNPRICED" '{"is_active":false}' "${OAUTH[@]}")
expect "a service can be retired" "$(status_of "$R")" "200"

# ---------------------------------------------------------------
echo
echo "[1b] The printable invoice (sec 4, sec 6)"

# A draft has a placeholder number and mutable lines, so there is nothing
# honest to print yet.
R=$(api POST /invoices "{\"patient_id\":$PATIENT,\"items\":[{\"service_id\":$NEWSVC,\"quantity\":1}]}" "${OAUTH[@]}")
DRAFT_INV=$(jnum "$(body_of "$R")" id)
CODE=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/invoices/$DRAFT_INV/pdf" \
    -H "Authorization: Bearer $OWNER")
expect "a draft invoice cannot be printed" "$CODE" "409"

R=$(api POST "/invoices/$DRAFT_INV/issue" '' "${OAUTH[@]}")
expect "issue it" "$(status_of "$R")" "200"

PDFFILE="${TMPDIR:-/tmp}/mediflow-invoice.pdf"
CODE=$(curl -s -o "$PDFFILE" -w '%{http_code}' "$BASE/invoices/$DRAFT_INV/pdf" \
    -H "Authorization: Bearer $OWNER")
expect "the issued invoice prints" "$CODE" "200"

case "$(head -c 8 "$PDFFILE")" in
  %PDF-*) pass "the response really is a PDF" ;;
  *)      fail "not a PDF: $(head -c 20 "$PDFFILE")" ;;
esac

# A PDF whose cross-reference table is wrong opens as a blank page in some
# readers and not at all in others, so the structure is checked, not just the
# first eight bytes.
STRUCT=$("$PHP" -r '
$pdf = file_get_contents($argv[1]);
if (!preg_match("/startxref\s+(\d+)/", $pdf, $m)) { exit("no-startxref"); }
if (substr($pdf, (int) $m[1], 4) !== "xref") { exit("bad-startxref"); }
preg_match("/xref\s+0 (\d+)\s+(.*?)trailer/s", $pdf, $x);
preg_match_all("/(\d{10}) (\d{5}) ([nf])/", $x[2] ?? "", $e, PREG_SET_ORDER);
foreach ($e as $i => $row) {
    if ($row[3] === "f") continue;
    if (substr($pdf, (int) $row[1], strlen("$i 0 obj")) !== "$i 0 obj") { exit("bad-offset-$i"); }
}
echo str_contains($pdf, "/Type /Page") ? "ok" : "no-page";
' "$PDFFILE" 2>/dev/null)
[ "$STRUCT" = "ok" ] && pass "its object table is intact" || fail "malformed PDF: $STRUCT"

grep -q "INVOICE" "$PDFFILE" && pass "it is laid out as an invoice" \
                             || fail "the document has no INVOICE heading"

# The number on the paper has to be the issued one, not the draft placeholder.
ISSUED_NO=$("$MYSQL" -u root "$DB" -N -e "SELECT invoice_no FROM invoices WHERE id=$DRAFT_INV")
grep -q "$ISSUED_NO" "$PDFFILE" && pass "it carries the issued number ($ISSUED_NO)" \
                                || fail "the printed number is not $ISSUED_NO"

# A prescription prints the same way, once issued.
RX=$("$MYSQL" -u root "$DB" -N -e "SELECT id FROM prescriptions WHERE organization_id=1 AND status='issued' LIMIT 1")
if [ -n "$RX" ]; then
  RXFILE="${TMPDIR:-/tmp}/mediflow-rx.pdf"
  CODE=$(curl -s -o "$RXFILE" -w '%{http_code}' "$BASE/prescriptions/$RX/pdf" \
      -H "Authorization: Bearer $OWNER")
  expect "an issued prescription prints" "$CODE" "200"
  grep -q "PRESCRIPTION" "$RXFILE" && pass "and is laid out as a prescription" \
                                   || fail "no PRESCRIPTION heading"
fi

R=$(api GET "/invoices/$DRAFT_INV/pdf" '' "${RAUTH[@]}")
case "$(status_of "$R")" in
  200) pass "reception can print an invoice" ;;
  *)   fail "reception blocked from printing -> $(status_of "$R")" ;;
esac

# ---------------------------------------------------------------
echo
echo "[2] Invoice totals are computed server-side"

# Scaling is PKR 4000 and taxable; PK tax is 17% exclusive -> 680 tax, 4680 total.
R=$(api POST /invoices "{\"patient_id\":$PATIENT,\"items\":[{\"service_id\":$SCALING,\"quantity\":1}]}" "${OAUTH[@]}")
expect "create draft invoice" "$(status_of "$R")" "201"
INV=$(jnum "$(body_of "$R")" id)
B=$(body_of "$R")
eq "subtotal"    "$(jmoney "$B" subtotal)"    "4000.00"
eq "tax (17% PK)" "$(jmoney "$B" tax_total)"  "680.00"
eq "grand total" "$(jmoney "$B" grand_total)" "4680.00"

# The client sends money and it must be ignored.
R=$(api POST /invoices "{\"patient_id\":$PATIENT,\"grand_total\":\"1.00\",\"tax_total\":\"0.00\",\"items\":[{\"service_id\":$SCALING,\"quantity\":1,\"unit_price\":\"1.00\"}]}" "${OAUTH[@]}")
expect "client-sent totals accepted as a request" "$(status_of "$R")" "201"
B=$(body_of "$R")
eq "client could not set grand_total" "$(jmoney "$B" grand_total)" "4680.00"
JUNK=$(jnum "$B" id)

# Quantity multiplies correctly: 3 x 4000 = 12000 + 17% = 14040
R=$(api POST /invoices "{\"patient_id\":$PATIENT,\"items\":[{\"service_id\":$SCALING,\"quantity\":3}]}" "${OAUTH[@]}")
B=$(body_of "$R")
eq "quantity x price" "$(jmoney "$B" grand_total)" "14040.00"
QTY=$(jnum "$B" id)

# Consultation is NOT taxable: 1500 stays 1500.
R=$(api POST /invoices "{\"patient_id\":$PATIENT,\"items\":[{\"service_id\":$CONSULT,\"quantity\":1}]}" "${OAUTH[@]}")
B=$(body_of "$R")
eq "non-taxable service pays no tax" "$(jmoney "$B" tax_total)" "0.00"
eq "non-taxable total"               "$(jmoney "$B" grand_total)" "1500.00"
NOTAX=$(jnum "$B" id)

# 10% discount on 4000 = 400 off -> 3600 + 17% = 4212
R=$(api POST /invoices "{\"patient_id\":$PATIENT,\"items\":[{\"service_id\":$SCALING,\"quantity\":1,\"discount_percent\":10}]}" "${OAUTH[@]}")
B=$(body_of "$R")
eq "discount applied"        "$(jmoney "$B" discount_total)" "400.00"
eq "tax after discount"      "$(jmoney "$B" tax_total)"      "612.00"
eq "total after discount"    "$(jmoney "$B" grand_total)"    "4212.00"
DISC=$(jnum "$B" id)

# DENT-SCALE caps discounts at 15%.
R=$(api POST /invoices "{\"patient_id\":$PATIENT,\"items\":[{\"service_id\":$SCALING,\"discount_percent\":40}]}" "${OAUTH[@]}")
expect "discount above the service cap refused" "$(status_of "$R")" "422"

R=$(api POST /invoices "{\"patient_id\":$PATIENT,\"items\":[{\"service_id\":$SCALING,\"discount_amount\":99999}]}" "${OAUTH[@]}")
expect "discount larger than the line refused" "$(status_of "$R")" "422"

R=$(api POST /invoices "{\"patient_id\":$PATIENT,\"items\":[]}" "${OAUTH[@]}")
expect "empty invoice refused" "$(status_of "$R")" "422"

R=$(api POST /invoices "{\"patient_id\":$PATIENT,\"items\":[{\"service_id\":999999}]}" "${OAUTH[@]}")
expect "unknown service refused" "$(status_of "$R")" "422"

R=$(api POST /invoices "{\"patient_id\":$PATIENT,\"items\":[{\"service_id\":$SCALING,\"quantity\":0}]}" "${OAUTH[@]}")
expect "zero quantity refused" "$(status_of "$R")" "422"

# ---------------------------------------------------------------
echo
echo "[3] Draft vs issued (sec 6)"

R=$(api GET "/invoices/$INV" '' "${OAUTH[@]}")
case "$(body_of "$R")" in
  *DRAFT-*) pass "draft carries a placeholder number" ;;
  *)        fail "draft number is not a placeholder" ;;
esac

R=$(api POST "/invoices/$INV/payments" '{"amount":100,"method":"cash"}' "${OAUTH[@]}")
expect "cannot pay a draft" "$(status_of "$R")" "409"

R=$(api PUT "/invoices/$INV" "{\"items\":[{\"service_id\":$SCALING,\"quantity\":2}]}" "${OAUTH[@]}")
expect "draft is editable" "$(status_of "$R")" "200"
eq "edited total" "$(jmoney "$(body_of "$R")" grand_total)" "9360.00"

R=$(api POST "/invoices/$INV/issue" '' "${OAUTH[@]}")
expect "issue invoice" "$(status_of "$R")" "200"
B=$(body_of "$R")
INVNO=$(jval "$B" invoice_no)
case "$INVNO" in
  INV-*) pass "real invoice number allocated at issue ($INVNO)" ;;
  *)     fail "invoice number not allocated: $INVNO" ;;
esac

R=$(api PUT "/invoices/$INV" "{\"items\":[{\"service_id\":$SCALING,\"quantity\":1}]}" "${OAUTH[@]}")
expect "issued invoice is immutable" "$(status_of "$R")" "409"

R=$(api POST "/invoices/$INV/issue" '' "${OAUTH[@]}")
expect "cannot issue twice" "$(status_of "$R")" "409"

# Abandoned drafts must not consume issued numbers.
R=$(api POST "/invoices/$NOTAX/issue" '' "${OAUTH[@]}")
NEXTNO=$(jval "$(body_of "$R")" invoice_no)
if [ "$INVNO" != "$NEXTNO" ]; then
  pass "sequence advanced ($INVNO -> $NEXTNO)"
else
  fail "invoice numbers collided"
fi

# ---------------------------------------------------------------
echo
echo "[4] Payments — one invoice, many payments (sec 6, sec 7)"

# Invoice INV is 9360.00
R=$(api POST "/invoices/$INV/payments" '{"amount":4000,"method":"cash"}' "${RAUTH[@]}")
expect "receptionist takes a part payment" "$(status_of "$R")" "201"
RECEIPT=$(jval "$(body_of "$R")" receipt_no)
case "$RECEIPT" in
  RCT-*) pass "receipt number issued ($RECEIPT)" ;;
  *)     fail "no receipt number" ;;
esac

R=$(api GET "/invoices/$INV" '' "${OAUTH[@]}")
B=$(body_of "$R")
eq "paid so far"    "$(jmoney "$B" paid_total)"  "4000.00"
eq "balance due"    "$(jmoney "$B" balance_due)" "5360.00"
eq "status"         "$(jval "$B" status)"        "partially_paid"

R=$(api POST "/invoices/$INV/payments" '{"amount":99999,"method":"cash"}' "${RAUTH[@]}")
expect "overpayment refused" "$(status_of "$R")" "409"

R=$(api POST "/invoices/$INV/payments" '{"amount":-50,"method":"cash"}' "${RAUTH[@]}")
expect "negative payment refused" "$(status_of "$R")" "422"

R=$(api POST "/invoices/$INV/payments" '{"amount":1360,"method":"card"}' "${RAUTH[@]}")
expect "second payment on the same invoice" "$(status_of "$R")" "201"

R=$(api POST "/invoices/$INV/payments" '{"amount":4000,"method":"bank_transfer"}' "${RAUTH[@]}")
expect "third payment settles it" "$(status_of "$R")" "201"

R=$(api GET "/invoices/$INV" '' "${OAUTH[@]}")
B=$(body_of "$R")
eq "fully paid"      "$(jmoney "$B" paid_total)"  "9360.00"
eq "balance cleared" "$(jmoney "$B" balance_due)" "0.00"
eq "status is paid"  "$(jval "$B" status)"        "paid"

R=$(api POST "/invoices/$INV/payments" '{"amount":1,"method":"cash"}' "${RAUTH[@]}")
expect "no payment on a settled invoice" "$(status_of "$R")" "409"

R=$(api POST "/invoices/$INV/cancel" '{"reason":"test"}' "${OAUTH[@]}")
expect "cannot cancel a paid invoice" "$(status_of "$R")" "409"

# ---------------------------------------------------------------
echo
echo "[5] Refunds (sec 7)"

PAYMENT=$("$MYSQL" -u root "$DB" -N -e "SELECT id FROM payments WHERE invoice_id=$INV ORDER BY id LIMIT 1")

R=$(api POST "/payments/$PAYMENT/refunds" '{"amount":500,"reason":"Overcharged for scaling"}' "${BAUTH[@]}")
expect "billing staff requests a refund" "$(status_of "$R")" "201"
REFUND=$(jnum "$(body_of "$R")" id)
eq "refund starts pending" "$(jval "$(body_of "$R")" status)" "pending"

R=$(api POST "/payments/$PAYMENT/refunds" '{"amount":99999,"reason":"too much"}' "${BAUTH[@]}")
expect "refund above the payment refused" "$(status_of "$R")" "409"

R=$(api POST "/refunds/$REFUND/approve" '' "${BAUTH[@]}")
expect "requester cannot approve their own refund" "$(status_of "$R")" "403"

R=$(api GET /refunds/pending '' "${OAUTH[@]}")
expect "pending refunds list" "$(status_of "$R")" "200"
case "$(body_of "$R")" in
  *"Overcharged"*) pass "the request appears for approval" ;;
  *)               fail "refund missing from the pending list" ;;
esac

R=$(api POST "/refunds/$REFUND/approve" '' "${OAUTH[@]}")
expect "owner approves the refund" "$(status_of "$R")" "200"

R=$(api GET "/invoices/$INV" '' "${OAUTH[@]}")
B=$(body_of "$R")
eq "paid_total reduced by the refund" "$(jmoney "$B" paid_total)" "8860.00"
eq "status reopened"                  "$(jval "$B" status)"       "partially_paid"

R=$(api POST "/refunds/$REFUND/approve" '' "${OAUTH[@]}")
expect "cannot approve twice" "$(status_of "$R")" "409"

# ---------------------------------------------------------------
echo
echo "[6] Consultation -> invoice (sec 27)"

# Find a completed encounter that has not been invoiced — and if every one has
# been (which is what a few runs of this suite leaves behind), hold one
# consultation to bill. The suite has to make its own subject rather than
# depending on leftovers from an earlier run.
ENC=$("$MYSQL" -u root "$DB" -N -e "SELECT e.id FROM encounters e LEFT JOIN invoices i ON i.encounter_id=e.id WHERE e.status='completed' AND i.id IS NULL ORDER BY e.id DESC LIMIT 1")

if [ -z "$ENC" ]; then
  # One open consultation per doctor (§4), so clear the way first.
  "$MYSQL" -u root "$DB" -e \
    "UPDATE encounters SET status='cancelled' WHERE status='open' AND organization_id=1;" 2>/dev/null

  DOCTOR_ID=$("$MYSQL" -u root "$DB" -N -e "SELECT id FROM doctors WHERE organization_id=1 ORDER BY id LIMIT 1")
  R=$(api POST /encounters \
      "{\"patient_id\":$PATIENT,\"doctor_id\":$DOCTOR_ID,\"chief_complaint\":\"Billing suite visit\"}" \
      "${DAUTH[@]}")
  ENC=$(jnum "$(body_of "$R")" id)

  if [ -n "$ENC" ]; then
    api POST "/encounters/$ENC/diagnoses" '{"description":"For the billing suite"}' "${DAUTH[@]}" > /dev/null
    api POST "/encounters/$ENC/complete" '' "${DAUTH[@]}" > /dev/null
  fi
fi

if [ -n "$ENC" ]; then
  R=$(api POST "/encounters/$ENC/invoice" '' "${OAUTH[@]}")
  expect "bill a completed consultation" "$(status_of "$R")" "201"
  B=$(body_of "$R")
  ENCINV=$(jnum "$B" id)
  case "$B" in
    *"Generated from consultation"*) pass "invoice notes reference the visit" ;;
    *)                               fail "invoice not linked to the visit" ;;
  esac

  R=$(api POST "/encounters/$ENC/invoice" '' "${OAUTH[@]}")
  expect "cannot bill the same visit twice" "$(status_of "$R")" "409"
else
  fail "no completed uninvoiced encounter to test with"
fi

# An open consultation is not billable.
OPENENC=$("$MYSQL" -u root "$DB" -N -e "SELECT id FROM encounters WHERE status='open' LIMIT 1")
if [ -n "$OPENENC" ]; then
  R=$(api POST "/encounters/$OPENENC/invoice" '' "${OAUTH[@]}")
  expect "cannot bill an open consultation" "$(status_of "$R")" "409"
fi

# ---------------------------------------------------------------
echo
echo "[7] Cancellation"

R=$(api POST "/invoices/$JUNK/cancel" '{"reason":"Raised in error"}' "${OAUTH[@]}")
expect "cancel an unpaid draft" "$(status_of "$R")" "200"
eq "status cancelled" "$(jval "$(body_of "$R")" status)" "cancelled"

R=$(api POST "/invoices/$JUNK/cancel" '{"reason":"again"}' "${OAUTH[@]}")
expect "cannot cancel twice" "$(status_of "$R")" "409"

R=$(api POST "/invoices/$QTY/cancel" '{}' "${OAUTH[@]}")
expect "cancellation needs a reason" "$(status_of "$R")" "422"

# ---------------------------------------------------------------
echo
echo "[8] Reports (sec 25 Phase 3)"

R=$(api GET /reports/financial '' "${OAUTH[@]}")
expect "financial summary" "$(status_of "$R")" "200"
B=$(body_of "$R")
case "$B" in
  *'"billed"'*)    pass "summary reports billed" ;;
  *)               fail "no billed figure" ;;
esac
case "$B" in
  *'"by_method"'*) pass "cash split by payment method" ;;
  *)               fail "no payment-method breakdown" ;;
esac
case "$B" in
  *'"top_services"'*) pass "top services included" ;;
  *)                  fail "no service breakdown" ;;
esac

R=$(api GET /reports/receivables '' "${OAUTH[@]}")
expect "aged receivables" "$(status_of "$R")" "200"
case "$(body_of "$R")" in
  *'"buckets"'*) pass "receivables bucketed by age" ;;
  *)             fail "no ageing buckets" ;;
esac

R=$(api GET /reports/financial '' "${RAUTH[@]}")
expect "receptionist blocked from reports" "$(status_of "$R")" "403"

# ---------------------------------------------------------------
echo
echo "[9] Payment ledger"

R=$(api GET /payments '' "${OAUTH[@]}")
expect "ledger reads" "$(status_of "$R")" "200"
case "$(body_of "$R")" in
  *"$RECEIPT"*) pass "the receipt appears in the ledger" ;;
  *)            fail "receipt missing from the ledger" ;;
esac

R=$(api GET "/payments?method=card" '' "${OAUTH[@]}")
expect "ledger filters by method" "$(status_of "$R")" "200"

# ---------------------------------------------------------------
echo
echo "[10] Audit (sec 16)"

R=$(api GET "/audit-logs?resource_type=invoice" '' "${OAUTH[@]}")
case "$(body_of "$R")" in
  *'"resource_type":"invoice"'*) pass "invoice events audited" ;;
  *)                             fail "invoices missing from the trail" ;;
esac

R=$(api GET "/audit-logs?resource_type=payment" '' "${OAUTH[@]}")
case "$(body_of "$R")" in
  *'"resource_type":"payment"'*) pass "payment events audited" ;;
  *)                             fail "payments missing from the trail" ;;
esac

# ---------------------------------------------------------------
echo
echo "====================================="
printf 'passed: %d   failed: %d\n\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ] || exit 1
