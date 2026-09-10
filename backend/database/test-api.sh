#!/usr/bin/env bash
# §19 API structure: /api/v1 and the twelve endpoint groups it names.
set -uo pipefail
BASE="${BASE:-http://127.0.0.1:8000/api/v1}"
MYSQL="${MYSQL:-/c/xampp/mysql/bin/mysql.exe}"
DB="${DB:-mediflow}"
PASS=0; FAIL=0
# See the note in the other suites: a bucket somebody else filled makes a
# 429 look like this suite's fault.
reset_limits() { [ -x "$MYSQL" ] && "$MYSQL" -u root "$DB" -e "TRUNCATE TABLE rate_limits;" 2>/dev/null; }
reset_limits
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

step "8. The README describes the surface that exists"
# README.md's "implemented endpoints" listing is what a new consumer reads
# before writing a single request. It is hand-maintained, and a document does
# not fail a build when it goes stale: this listing had drifted by 82 routes —
# five of the twelve groups §19 names, /billing, /payments, /insurance, /claims
# and the staff /notifications, were absent entirely — and nothing noticed.
PHP="${PHP:-C:/xampp/php/php.exe}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
if [ ! -x "$PHP" ]; then
  bad "php not found at $PHP" "the README listing was NOT checked"
else
  DUMP="$(dirname "$0")/.routes-dump.php"
  cat > "$DUMP" <<'PHPDUMP'
<?php
declare(strict_types=1);
$b = getenv('MF_BACKEND');
require $b . '/bootstrap/app.php';
$router = new \App\Core\Router();
$router->registerAliases([
    'throttle' => \App\Middleware\RateLimitMiddleware::class,
    'auth'     => \App\Middleware\AuthMiddleware::class,
    'tenant'   => \App\Middleware\TenantMiddleware::class,
    'perm'     => \App\Middleware\PermissionMiddleware::class,
    'platform' => \App\Middleware\PlatformAdminMiddleware::class,
]);
require $b . '/routes/api.php';
foreach ($router->routeTable() as $r) {
    $p = substr($r['path'], strlen('/api/v1'));
    echo $r['method'], ' ', ($p === '' ? '/api/v1' : $p), "\n";
}
PHPDUMP
  MF_BACKEND="$ROOT/backend" "$PHP" "$DUMP" 2>/dev/null | tr -d '\r' | sort -u > /tmp/mf-routes.$$
  rm -f "$DUMP"
  REG=$(wc -l < /tmp/mf-routes.$$)
  [ "$REG" -gt 150 ] && ok "the route table dumps ($REG routes)" \
                     || bad "could not read the route table" "got $REG lines"

  # The listing runs from its heading to the next one.
  awk '/^## API — implemented endpoints/{f=1} f&&/^## /&&!/implemented endpoints/{exit} f' \
    "$ROOT/README.md" \
    | grep -E '^(GET|POST|PUT|PATCH|DELETE) +/' \
    | awk '{print $1" "$2}' | sed 's/?.*//' | sort -u > /tmp/mf-readme.$$

  # /billing and /payments were carved out of flat paths that shipped first.
  # Both spellings stay, and the README says so in prose instead of listing
  # each twice. Every exemption below is proved to be a true alias further
  # down, so this list cannot be used to hide a route that is merely missing.
  cat > /tmp/mf-alias.$$ <<'ALIASES'
GET /services
POST /services
PUT /services/{id}
POST /services/{id}/prices
GET /invoices
POST /invoices
GET /invoices/{id}
PUT /invoices/{id}
GET /invoices/{id}/pdf
POST /invoices/{id}/issue
POST /invoices/{id}/cancel
GET /reports/financial
GET /reports/receivables
ALIASES
  sort -u /tmp/mf-alias.$$ -o /tmp/mf-alias.$$

  MISSING=$(comm -13 /tmp/mf-readme.$$ /tmp/mf-routes.$$ | comm -23 - /tmp/mf-alias.$$)
  if [ -z "$MISSING" ]; then
    ok "every registered route is in the listing, or a documented alias"
  else
    bad "the README omits $(printf '%s\n' "$MISSING" | wc -l) routes" \
        "$(printf '%s' "$MISSING" | tr '\n' ' ')"
  fi

  INVENTED=$(comm -23 /tmp/mf-readme.$$ /tmp/mf-routes.$$)
  if [ -z "$INVENTED" ]; then
    ok "and the listing invents none"
  else
    bad "the README lists $(printf '%s\n' "$INVENTED" | wc -l) routes that do not exist" \
        "$(printf '%s' "$INVENTED" | tr '\n' ' ')"
  fi
  rm -f /tmp/mf-routes.$$ /tmp/mf-readme.$$ /tmp/mf-alias.$$
fi

step "9. The aliases the listing skips really are aliases"
# Otherwise step 8's exemption list is just a place to hide missing docs.
INV=$(curl -s "${O[@]}" "$BASE/billing/invoices" | grep -o '"id":[0-9]\+' | head -1 | cut -d: -f2)
[ -n "$INV" ] && ok "found invoice $INV to compare with" || bad "no invoice to compare with"
# The owner holds every permission, so asking only the owner cannot tell a
# true alias from two paths carrying different guards: both answer 200 either
# way. A receptionist may read the catalogue but not manage it, which is
# exactly the divergence that would otherwise go unseen.
RTOK=$(tok reception@clinic.test)
[ -n "$RTOK" ] && ok "and a lesser account to ask as" || bad "cannot sign in as reception"
R=(-H "Authorization: Bearer $RTOK" -H 'X-Organization-Id: 1' -H 'Content-Type: application/json')

same() { # the flat and the /billing spelling must agree, for everybody
  local who a b; local -a H
  for who in owner reception; do
    if [ "$who" = owner ]; then H=("${O[@]}"); else H=("${R[@]}"); fi
    a=$(curl -s -w '|%{http_code}' "${H[@]}" "$BASE$1")
    b=$(curl -s -w '|%{http_code}' "${H[@]}" "$BASE/billing$1")
    if [ "$a" = "$b" ]; then ok "$1 == /billing$1 (as $who)"
    else bad "$1 != /billing$1 as $who" "flat [${a##*|}] billing [${b##*|}]"; fi
  done
}
same /services
same /invoices
same "/invoices/$INV"
same /reports/financial
same /reports/receivables
# The writes are compared against a target that cannot exist, so the routes
# answer without doing anything: same code means same route and same guard.
codes() {
  local who a b; local -a H
  for who in owner reception; do
    if [ "$who" = owner ]; then H=("${O[@]}"); else H=("${R[@]}"); fi
    a=$(curl -s -o /dev/null -w '%{http_code}' -X "$1" "${H[@]}" -d '{}' "$BASE$2")
    b=$(curl -s -o /dev/null -w '%{http_code}' -X "$1" "${H[@]}" -d '{}' "$BASE/billing$2")
    if [ "$a" = "$b" ]; then ok "$1 $2 == /billing$2 ($a, as $who)"
    else bad "$1 $2 != /billing$2 as $who" "flat [$a] billing [$b]"; fi
  done
}
codes POST /services
codes PUT  /services/99999
codes POST /services/99999/prices
codes POST /invoices
codes PUT  /invoices/99999
codes POST /invoices/99999/issue
codes POST /invoices/99999/cancel
codes GET  /invoices/99999/pdf

step "10. A paged list is stable enough to page through"
# A list ordered on created_at alone has no defined order among rows that
# share a second, so MySQL is free to return them differently each time. On
# a paged endpoint that is not cosmetic: a row sitting on a page boundary
# gets shown on both pages, or on neither. /invoices did exactly this — two
# consecutive requests returned two different lists — and it surfaced only
# because the alias check in step 9 compared the same list twice.
for ep in invoices claims patients prescriptions; do
  A=$(curl -s "${O[@]}" "$BASE/$ep?page=1&per_page=10" | md5sum | cut -d' ' -f1)
  B=$(curl -s "${O[@]}" "$BASE/$ep?page=1&per_page=10" | md5sum | cut -d' ' -f1)
  [ "$A" = "$B" ] && ok "/$ep returns the same page twice running" \
                  || bad "/$ep is not stable between requests"

  P1=$(curl -s "${O[@]}" "$BASE/$ep?page=1&per_page=10" | grep -o '"id":[0-9]\+' | cut -d: -f2 | sort -u)
  P2=$(curl -s "${O[@]}" "$BASE/$ep?page=2&per_page=10" | grep -o '"id":[0-9]\+' | cut -d: -f2 | sort -u)
  if [ -z "$P1" ] || [ -z "$P2" ]; then
    ok "/$ep has too few rows to page (nothing to prove)"
  else
    DUP=$(comm -12 <(printf '%s\n' "$P1") <(printf '%s\n' "$P2") | wc -l)
    [ "$DUP" -eq 0 ] && ok "and no row appears on both page 1 and page 2" \
                     || bad "/$ep shows $DUP row(s) on two pages at once"
  fi
done

echo
echo "========================================="
echo "passed: $PASS   failed: $FAIL"
[ "$FAIL" -eq 0 ]
