#!/usr/bin/env bash
# §25 development phases: every deliverable each phase names, and whether
# docs/PHASES.md still tells the truth about them.
#
# PHASES.md opens by promising that every number in it came from the live
# database. That promise decayed quietly: it claimed 44 tables and 99 foreign
# keys when there were 45 and 100, and quoted suite sizes from a version of
# the suites that had grown by a third. A document nobody checks is a document
# that is right once.
set -uo pipefail
BASE="${BASE:-http://127.0.0.1:8000/api/v1}"
MYSQL="${MYSQL:-/c/xampp/mysql/bin/mysql.exe}"
DB="${DB:-mediflow}"
REPO="${REPO:-/c/Users/Mr Shahram/mediflow}"
DOC="$REPO/docs/PHASES.md"
PASS=0; FAIL=0
reset_limits() { [ -x "$MYSQL" ] && "$MYSQL" -u root "$DB" -e "TRUNCATE TABLE rate_limits;" 2>/dev/null; }
reset_limits
step() { printf '\n\033[1m%s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m  %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m  %s  %s\n' "$1" "${2:-}"; }
want() { if [ "$2" = "$3" ]; then ok "$1 -> $2"; else bad "$1" "got [$2] want [$3]"; fi; }
sql()  { "$MYSQL" -u root "$DB" -N -e "$1" 2>/dev/null | tr -d '\r'; }

TOK=$(curl -s -X POST "$BASE/auth/login" -H 'Content-Type: application/json' \
        -d '{"email":"owner@clinic.test","password":"Password123"}' \
      | grep -o '"access_token":"[^"]*"' | head -1 | cut -d'"' -f4)
[ -z "$TOK" ] && { echo "cannot sign in"; exit 1; }
O=(-H "Authorization: Bearer $TOK" -H 'X-Organization-Id: 1')
code() { curl -s -o /dev/null -w '%{http_code}' "${O[@]}" "$BASE$1"; }

[ -f "$DOC" ] && ok "docs/PHASES.md is where it says it is" || { bad "no PHASES.md"; exit 1; }

step "1. Each phase's named deliverables exist as real tables"
# Straight from §25: the six phases and what each one promised to deliver.
check_tables() {
  local phase="$1"; shift
  for t in "$@"; do
    N=$(sql "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema='$DB' AND table_name='$t'")
    [ "${N:-0}" = "1" ] && ok "$phase: $t" || bad "$phase: '$t' does not exist"
  done
}
check_tables "P1 foundation" users roles permissions organizations organization_users audit_logs
check_tables "P2 healthcare" patients doctors appointments encounters diagnoses prescriptions
check_tables "P3 billing"    services service_prices invoices payments refunds
check_tables "P5 insurance"  insurance_providers insurance_policies claims claim_items
check_tables "P6 ai"         clinical_notes

step "2. The structural claims in the document are exact"
DBROW=$(grep -F 'tables |' "$DOC" | head -1)
DOCTBL=$(printf '%s' "$DBROW" | grep -oE '[0-9]+ tables' | grep -oE '[0-9]+')
REAL=$(sql "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema='$DB' AND table_type='BASE TABLE'")
want "the table count it prints" "${DOCTBL:-none}" "$REAL"

DOCFK=$(printf '%s' "$DBROW" | grep -oE '[0-9]+ foreign keys' | grep -oE '[0-9]+')
REALFK=$(sql "SELECT COUNT(*) FROM information_schema.table_constraints
               WHERE table_schema='$DB' AND constraint_type='FOREIGN KEY'")
want "the foreign key count" "${DOCFK:-none}" "$REALFK"

DOCRBAC=$(grep -oE '[0-9]+ roles, [0-9]+ permissions, [0-9]+ mappings' "$DOC" | head -1)
REALRBAC="$(sql 'SELECT COUNT(*) FROM roles') roles, $(sql 'SELECT COUNT(*) FROM permissions') permissions, $(sql 'SELECT COUNT(*) FROM role_permissions') mappings"
want "the RBAC figures" "${DOCRBAC:-none}" "$REALRBAC"

step "3. No row count in the document is larger than the truth"
# Demo data only grows, so an overstated figure means the document is
# describing a database that never existed. An understated one just means
# somebody seeded since — that is not a lie, so it is not a failure.
OVER=0; SEEN=0
while IFS='|' read -r _ name count _; do
  name=$(printf '%s' "$name" | sed 's/^ *//; s/ *$//; s/ (consultations)//; s/ (catalogue)//')
  count=$(printf '%s' "$count" | tr -d ' ,')
  case "$name" in
    patients|doctors|appointments|encounters|diagnoses|procedures|prescriptions|\
    prescription_items|medications|allergies|medical_conditions|services|\
    service_prices|invoices|invoice_items|payments|refunds|insurance_providers|\
    insurance_policies|claims|claim_items)
      REAL=$(sql "SELECT COUNT(*) FROM \`$name\`")
      SEEN=$((SEEN+1))
      if [ "${REAL:-0}" -lt "${count:-0}" ]; then
        OVER=$((OVER+1))
        printf '      %s: document says %s, database has %s\n' "$name" "$count" "$REAL"
      fi
      ;;
  esac
done < "$DOC"
[ "$SEEN" -ge 15 ] && ok "read $SEEN row claims out of the document" \
                   || bad "found almost no row claims to check" "only $SEEN"
want "none of them overstates the database" "$OVER" "0"

step "4. Every suite the document credits actually exists"
NAMED=$(grep -oE '`(smoke-)?test[a-z-]*`|`smoke-test`' "$DOC" | tr -d '`' | sort -u)
MISSING=0; COUNTED=0
for s in $NAMED; do
  COUNTED=$((COUNTED+1))
  [ -f "$REPO/backend/database/$s.sh" ] || { MISSING=$((MISSING+1)); printf '      no such suite: %s\n' "$s"; }
done
[ "$COUNTED" -ge 10 ] && ok "the document names $COUNTED suites" \
                      || bad "the document credits almost no suites" "$COUNTED"
want "and every one of them is a file" "$MISSING" "0"

DOCSUITES=$(grep -E '^\| Test suites \|' "$DOC" | grep -oE '[0-9]+' | head -1)
REALSUITES=$(ls "$REPO"/backend/database/*.sh 2>/dev/null | grep -v 'run-all-tests' | wc -l | tr -d ' ')
want "and the total it prints is the number there are" "${DOCSUITES:-none}" "$REALSUITES"

step "5. Phase 4 screens and Phase 6 endpoints are not just prose"
while read -r f; do
  [ -f "$REPO/patient_app/app/$f" ] && ok "screen $f" || bad "screen '$f' is named but missing"
done <<'FILES'
login.js
signup.js
forgot.js
(tabs)/index.js
(tabs)/profile.js
book.js
(tabs)/appointments.js
(tabs)/records.js
(tabs)/bills.js
notifications.js
account.js
FILES

# §25 Phase 6 lists five AI endpoints. With no provider configured they answer
# 503 by design — what must not happen is 404, which would mean the endpoint
# the document promises was never wired at all.
for ep in "/ai/status" "/encounters/1/ai/billing-suggestions" "/claims/1/ai/review" "/patients/1/ai/summary"; do
  C=$(code "$ep")
  [ "$C" != "404" ] && ok "$ep is wired ($C)" || bad "$ep is documented but 404s"
done
C=$(curl -s -o /dev/null -w '%{http_code}' -X POST "${O[@]}" -H 'Content-Type: application/json' \
      -d '{"type":"soap"}' "$BASE/encounters/1/ai/draft-note")
[ "$C" != "404" ] && ok "/encounters/{id}/ai/draft-note is wired ($C)" \
                  || bad "the draft-note endpoint 404s"

echo
echo "========================================="
echo "passed: $PASS   failed: $FAIL"
[ "$FAIL" -eq 0 ]
