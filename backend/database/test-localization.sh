#!/usr/bin/env bash
# §23 localization: country, currency, timezone, tax, dates and invoice
# formats configurable per market — and honest about compliance.
set -uo pipefail
BASE="${BASE:-http://127.0.0.1:8000/api/v1}"
MYSQL="${MYSQL:-/c/xampp/mysql/bin/mysql.exe}"
DB="${DB:-mediflow}"
REPO="${REPO:-/c/Users/Mr Shahram/mediflow}"
# PHP here is the Windows binary: it does not understand msys paths like
# /c/Users, so anything handed to php -r needs the drive-letter form.
WREPO="${WREPO:-C:/Users/Mr Shahram/mediflow}"
PHP="${PHP:-C:/xampp/php/php.exe}"
PASS=0; FAIL=0
step() { printf '\n\033[1m%s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m  %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m  %s  %s\n' "$1" "${2:-}"; }
want() { if [ "$2" = "$3" ]; then ok "$1 -> $2"; else bad "$1" "got [$2] want [$3]"; fi; }
sql()  { "$MYSQL" -u root "$DB" -N -e "$1" 2>/dev/null | tr -d '\r'; }

step "1. The four named markets are configured"
for m in PK US GB AE; do
  ROW=$(sql "SELECT CONCAT_WS('|', currency_code, timezone, date_format,
                     default_tax_rate, invoice_prefix)
             FROM countries WHERE code='$m'")
  [ -n "$ROW" ] && ok "$m: $ROW" || bad "$m is not configured"
done
# The markets must not all be the same row with a different name.
N=$(sql "SELECT COUNT(DISTINCT date_format) FROM countries WHERE code IN ('PK','US','GB','AE')")
[ "${N:-0}" -ge 2 ] && ok "their date formats genuinely differ ($N distinct)" \
                    || bad "every market shares one date format"
N=$(sql "SELECT COUNT(DISTINCT currency_code) FROM countries WHERE code IN ('PK','US','GB','AE')")
want "and so do their currencies" "$N" "4"
N=$(sql "SELECT COUNT(DISTINCT timezone) FROM countries WHERE code IN ('PK','US','GB','AE')")
want "and their timezones" "$N" "4"

step "2. The pound sign survived the database"
HEX=$(sql "SELECT HEX(currency_symbol) FROM countries WHERE code='GB'")
want "GB symbol is UTF-8 £" "$HEX" "C2A3"

step "3. A stored UTC moment reads correctly in each market"
OUT=$("$PHP" -r '
require "'"$WREPO"'/backend/bootstrap/app.php";
use App\Services\Documents\Locale;
$when = "2026-09-09 22:30:00";
$m = [
 "PK" => ["date_format"=>"d/m/Y","timezone"=>"Asia/Karachi","currency_symbol"=>"Rs"],
 "US" => ["date_format"=>"m/d/Y","timezone"=>"America/New_York","currency_symbol"=>"$"],
 "GB" => ["date_format"=>"d/m/Y","timezone"=>"Europe/London","currency_symbol"=>"GBP"],
 "AE" => ["date_format"=>"d/m/Y","timezone"=>"Asia/Dubai","currency_symbol"=>"AED"],
 "XX" => [],
];
foreach ($m as $k => $c) {
  $l = Locale::forClinic($c);
  echo $k . "=" . $l->date($when) . ";" . $l->money(1234.5) . "\n";
}' 2>&1)

echo "$OUT" | grep -q '^US=09/09/2026' && ok "US writes 09/09/2026"        || bad "US date wrong" "$(echo "$OUT" | grep '^US=')"
echo "$OUT" | grep -q '^PK=10/09/2026' && ok "PK is already on the 10th"   || bad "PK date wrong" "$(echo "$OUT" | grep '^PK=')"
echo "$OUT" | grep -q '^AE=10/09/2026' && ok "so is the UAE"               || bad "AE date wrong" "$(echo "$OUT" | grep '^AE=')"
echo "$OUT" | grep -q '^GB=09/09/2026' && ok "the UK is still on the 9th"  || bad "GB date wrong" "$(echo "$OUT" | grep '^GB=')"
# A market with nothing configured must not guess between 09/10 and 10/09.
echo "$OUT" | grep -q '^XX=09 Sep 2026' && ok "an unconfigured market falls back to an unambiguous format" \
                                        || bad "the fallback is ambiguous" "$(echo "$OUT" | grep '^XX=')"
echo "$OUT" | grep -q 'US=.*;\$ 1,234.50' && ok "money carries the market's mark" \
                                          || bad "currency symbol not applied"

step "4. Tax behaviour is per market, not hard-coded"
OUT=$("$PHP" -r '
require "'"$WREPO"'/backend/bootstrap/app.php";
use App\Services\Billing\TaxRules;
foreach (["PK","US","GB","AE","ZZ"] as $c) {
  echo $c . "=" . get_class(TaxRules::forCountry($c)) . "\n";
}' 2>&1)
echo "$OUT" | grep -q 'GB=.*TaxInclusiveRule'  && ok "GB is tax-inclusive (VAT)"  || bad "GB rule wrong"
echo "$OUT" | grep -q 'PK=.*TaxExclusiveRule'  && ok "PK is tax-exclusive (GST)"  || bad "PK rule wrong"
echo "$OUT" | grep -q 'ZZ=.*TaxExclusiveRule'  && ok "an unknown market still gets a rule" || bad "unknown market has no rule"

step "5. A clinic's own setting beats its market's default"
# `mysql -N` prints a SQL NULL as the four letters NULL, so capturing the
# current value and writing it straight back sets the column to the string
# 'NULL' — truncated by the column to 'NUL'. That corrupts the demo clinic's
# currency and every invoice issued afterwards. Ask whether it IS null instead
# of what it looks like.
WAS_NULL=$(sql "SELECT currency_code IS NULL FROM organizations WHERE id=1")
BEFORE=$(sql "SELECT COALESCE(currency_code,'') FROM organizations WHERE id=1")

sql "UPDATE organizations SET currency_code='USD' WHERE id=1" >/dev/null
RESOLVED=$(sql "SELECT COALESCE(o.currency_code, c.currency_code)
                  FROM organizations o JOIN countries c ON c.id=o.country_id
                 WHERE o.id=1")
want "an override wins" "$RESOLVED" "USD"

# Clearing it must bring the market default back.
sql "UPDATE organizations SET currency_code=NULL WHERE id=1" >/dev/null
RESOLVED=$(sql "SELECT COALESCE(o.currency_code, c.currency_code)
                  FROM organizations o JOIN countries c ON c.id=o.country_id
                 WHERE o.id=1")
want "and clearing it falls back to the market" "$RESOLVED" "PKR"

# Restore exactly what was there — a real NULL if that is what it was.
if [ "$WAS_NULL" = "1" ] || [ -z "$BEFORE" ]; then
  sql "UPDATE organizations SET currency_code=NULL WHERE id=1" >/dev/null
else
  sql "UPDATE organizations SET currency_code='$BEFORE' WHERE id=1" >/dev/null
fi
CHECK=$(sql "SELECT COALESCE(currency_code,'(null)') FROM organizations WHERE id=1")
echo "$CHECK" | grep -qE '^(NUL|NULL)$' && bad "the test corrupted the clinic's currency" "$CHECK" \
                                        || ok "and the clinic is left as it was ($CHECK)"

step "6. Documents actually render with all this"
TOK=$(curl -s -X POST "$BASE/auth/login" -H 'Content-Type: application/json' \
        -d '{"email":"owner@clinic.test","password":"Password123"}' \
      | grep -o '"access_token":"[^"]*"' | head -1 | cut -d'"' -f4)
[ -z "$TOK" ] && { echo "cannot sign in"; exit 1; }
O=(-H "Authorization: Bearer $TOK" -H 'X-Organization-Id: 1')

INV=$(sql "SELECT id FROM invoices WHERE organization_id=1 AND status<>'draft' ORDER BY id DESC LIMIT 1")
C=$(curl -s -o /dev/null -w '%{http_code}' "${O[@]}" "$BASE/invoices/$INV/pdf")
want "an invoice PDF renders" "$C" "200"
TYPE=$(curl -s -D- -o /dev/null "${O[@]}" "$BASE/invoices/$INV/pdf" | grep -i '^content-type' | tr -d '\r')
echo "$TYPE" | grep -qi 'application/pdf' && ok "and is served as a PDF" || bad "wrong content type" "$TYPE"

RX=$(sql "SELECT id FROM prescriptions WHERE organization_id=1 AND status='issued' ORDER BY id DESC LIMIT 1")
C=$(curl -s -o /dev/null -w '%{http_code}' "${O[@]}" "$BASE/prescriptions/$RX/pdf")
want "a prescription PDF renders" "$C" "200"

step "7. Markets are editable at runtime, not in code"
C=$(curl -s -o /dev/null -w '%{http_code}' "${O[@]}" "$BASE/public/countries")
want "the open markets are listable" "$C" "200"
curl -s "$BASE/public/countries" | grep -q '"currency_symbol"' \
  && ok "with their currency marks" || bad "no currency symbol in the list"
# A closed market must not be offered to a clinic signing up.
sql "SELECT COUNT(*) FROM countries WHERE is_active=0" >/dev/null
CLOSED=$(sql "SELECT code FROM countries WHERE is_active=0 LIMIT 1")
if [ -n "$CLOSED" ]; then
  curl -s "$BASE/public/countries" | grep -q "\"$CLOSED\"" \
    && bad "closed market $CLOSED is still offered" || ok "a closed market ($CLOSED) is not offered"
else
  ok "every configured market is open"
fi

step "8. No compliance is claimed that has not been earned"
grep -qi 'no compliance claim should be made' "$REPO/README.md" \
  && ok "the README says so plainly" || bad "the README does not qualify its compliance wording"
# The words "HIPAA compliant" / "GDPR compliant" must appear nowhere.
if grep -rniE '(HIPAA|GDPR)[- ]complian|complian[a-z]* with (HIPAA|GDPR)|fully complian' \
     "$REPO/README.md" "$REPO/backend/app" 2>/dev/null | grep -q .; then
  bad "something claims formal compliance"
else
  ok "nothing claims formal compliance"
fi
# But the design should still cite what it was built against.
grep -q 'HIPAA' "$REPO/backend/app/Services/DataExportService.php" \
  && ok "and the code cites the regulation it answers" || bad "no regulation cited in the export"

echo
echo "========================================="
echo "passed: $PASS   failed: $FAIL"
[ "$FAIL" -eq 0 ]
