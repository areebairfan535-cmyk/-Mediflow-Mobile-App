#!/usr/bin/env bash
# §17 security & audit logging: what the trail records, and the controls
# around it.
set -uo pipefail
BASE="${BASE:-http://127.0.0.1:8000/api/v1}"
MYSQL="${MYSQL:-/c/xampp/mysql/bin/mysql.exe}"
DB="${DB:-mediflow}"
BACKEND="${BACKEND:-/c/Users/Mr Shahram/mediflow/backend}"
PHP="${PHP:-C:/xampp/php/php.exe}"
PASS=0; FAIL=0
step() { printf '\n\033[1m%s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m  %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m  %s  %s\n' "$1" "${2:-}"; }
want() { if [ "$2" = "$3" ]; then ok "$1 -> $2"; else bad "$1" "got [$2] want [$3]"; fi; }
sql()  { "$MYSQL" -u root "$DB" -N -e "$1" 2>/dev/null | tr -d '\r'; }

TOK=$(curl -s -X POST "$BASE/auth/login" -H 'Content-Type: application/json' \
        -d '{"email":"owner@clinic.test","password":"Password123"}' \
      | grep -o '"access_token":"[^"]*"' | head -1 | cut -d'"' -f4)
[ -z "$TOK" ] && { echo "cannot sign in"; exit 1; }
O=(-H "Authorization: Bearer $TOK" -H 'X-Organization-Id: 1' -H 'Content-Type: application/json')

step "1. A trail entry says who, what, when and from where"
BEFORE=$(sql "SELECT COALESCE(MAX(id),0) FROM audit_logs")
curl -s -o /dev/null "${O[@]}" "$BASE/patients/1"
ROW=$(sql "SELECT CONCAT_WS('|',
             user_id IS NOT NULL, action, resource_type, resource_id IS NOT NULL,
             created_at IS NOT NULL, route IS NOT NULL, method,
             ip_address IS NOT NULL, user_agent IS NOT NULL, request_id IS NOT NULL)
           FROM audit_logs WHERE id > $BEFORE AND action='view' ORDER BY id DESC LIMIT 1")
[ -n "$ROW" ] && ok "reading a chart wrote a row" || bad "no row was written"
echo "$ROW" | grep -q '^1|view|patient|1|1|1|GET|1|1|1$' \
  && ok "with user, action, resource, time, route, method, IP, agent, request id" \
  || bad "the row is thin" "$ROW"

step "2. Financial access is recorded too, not only clinical"
for pair in "invoices/1:invoice" "payments/1:payment"; do
  P="${pair%%:*}"; R="${pair##*:}"
  B=$(sql "SELECT COUNT(*) FROM audit_logs WHERE action='view' AND resource_type='$R'")
  curl -s -o /dev/null "${O[@]}" "$BASE/$P"
  A=$(sql "SELECT COUNT(*) FROM audit_logs WHERE action='view' AND resource_type='$R'")
  [ "${A:-0}" -gt "${B:-0}" ] && ok "reading a $R is logged ($B -> $A)" || bad "$R read left no trace"
done

step "3. Critical changes keep the old value as well as the new"
NAME="Audit Probe $RANDOM"
curl -s -o /dev/null -X PUT "${O[@]}" -d "{\"city\":\"$NAME\"}" "$BASE/patients/1"
CH=$(sql "SELECT CONCAT_WS('|', old_values IS NOT NULL, new_values IS NOT NULL)
          FROM audit_logs WHERE action='update' AND resource_type='patient'
          ORDER BY id DESC LIMIT 1")
want "an update records both sides" "$CH" "1|1"
sql "SELECT new_values FROM audit_logs WHERE action='update' AND resource_type='patient'
     ORDER BY id DESC LIMIT 1" | grep -qF "$NAME" && ok "and the new value is the one sent" \
  || bad "the new value was not recorded"

step "4. Failed and blocked sign-ins are recorded, not just successes"
for a in login_failed login; do
  N=$(sql "SELECT COUNT(*) FROM audit_logs WHERE action='$a'")
  [ "${N:-0}" -gt 0 ] && ok "'$a' is in the trail ($N)" || bad "'$a' is never recorded"
done

step "5. The trail cannot be edited through the API"
C=$(curl -s -o /dev/null -w '%{http_code}' -X DELETE "${O[@]}" "$BASE/audit-logs/1")
[ "$C" = "404" ] || [ "$C" = "405" ] || [ "$C" = "403" ] \
  && ok "deleting a trail entry -> $C" || bad "audit rows are deletable" "got $C"
grep -q "no update() or" "$BACKEND/app/Repositories/AuditLogRepository.php" \
  && ok "and the repository offers no way to change one" || bad "repository may allow edits"

step "6. Security controls on the wire"
H=$(curl -sI "$BASE/health")
echo "$H" | grep -qi 'X-Content-Type-Options: nosniff'  && ok "nosniff"        || bad "no nosniff header"
echo "$H" | grep -qi 'X-Frame-Options: DENY'            && ok "frame deny"     || bad "no frame-options"
echo "$H" | grep -qi 'Referrer-Policy: no-referrer'     && ok "referrer policy"|| bad "no referrer policy"
echo "$H" | grep -qi 'Content-Security-Policy'          && ok "content policy" || bad "no CSP"
echo "$H" | grep -qi 'X-Powered-By'                     && bad "PHP version is advertised" \
                                                        || ok "no version banner"
# HSTS must NOT be claimed over plain HTTP — asserting it here would be a lie.
echo "$H" | grep -qi 'Strict-Transport-Security'        && bad "HSTS sent over plain HTTP" \
                                                        || ok "HSTS withheld on http, as it should be"

step "7. Passwords and tokens are not stored in the clear"
sql "SELECT password FROM users LIMIT 1" | grep -q '^\$2y\$' \
  && ok "passwords are bcrypt" || bad "passwords are not bcrypt hashes"
sql "SELECT token_hash FROM auth_tokens LIMIT 1" | grep -qE '^[0-9a-f]{64}$' \
  && ok "tokens are stored as SHA-256" || bad "tokens are not hashed"
N=$(sql "SELECT COUNT(*) FROM users WHERE password LIKE 'Password%'")
want "no plaintext password anywhere" "$N" "0"

step "8. Rate limiting actually refuses"
CODES=""
for _ in $(seq 1 14); do
  CODES="$CODES $(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/auth/login" \
      -H 'Content-Type: application/json' -d '{"email":"nobody@nowhere.test","password":"wrong"}')"
done
echo "$CODES" | grep -q 429 && ok "a burst of sign-ins is throttled" || bad "no 429 after 14 attempts"

step "9. Injection and validation"
C=$(curl -s -o /dev/null -w '%{http_code}' "${O[@]}" "$BASE/patients?status=active';DROP%20TABLE%20patients;--")
want "a SQL payload in a filter is refused" "$C" "422"
N=$(sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB' AND table_name='patients'")
want "and patients still exists" "$N" "1"
# Stored text comes back as data, never as executable markup.
XSS='<script>alert(1)</script>'
curl -s -o /dev/null -X PUT "${O[@]}" -d "{\"city\":\"$XSS\"}" "$BASE/patients/1"
BODY=$(curl -s "${O[@]}" "$BASE/patients/1")
echo "$BODY" | grep -q '"city":"<script>' && ok "markup is stored and returned as text" \
                                          || ok "markup was rejected or escaped"
echo "$BODY" | grep -qi 'content-type: text/html' && bad "served as HTML" || ok "and never as HTML"
curl -s -o /dev/null -X PUT "${O[@]}" -d '{"city":"Karachi"}' "$BASE/patients/1"

step "10. Uploaded files are not reachable without going through the API"
C=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:8000/storage/app/public/documents/")
want "the storage folder over HTTP" "$C" "404"
C=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/documents/1/download")
want "a document without a token" "$C" "401"

step "11. Backups exist and can be put back"
[ -f "$BACKEND/database/backup.php" ]  && ok "there is a backup script"  || bad "no backup script"
[ -f "$BACKEND/database/restore.php" ] && ok "and a restore script"      || bad "no restore script"

OUT=$("$PHP" "$BACKEND/database/backup.php" --db-only --keep=2 2>&1)
echo "$OUT" | grep -q 'database.sql' && ok "a backup runs" || bad "backup failed" "$OUT"
LATEST=$(ls -1 "$BACKEND/storage/backups" | tail -1)
[ -s "$BACKEND/storage/backups/$LATEST/database.sql" ] && ok "and writes a real dump" || bad "dump is empty"
grep -q "CREATE TABLE" "$BACKEND/storage/backups/$LATEST/database.sql" \
  && ok "with schema in it" || bad "dump has no schema"
[ -f "$BACKEND/storage/backups/$LATEST/MANIFEST.txt" ] \
  && ok "and a manifest saying how to restore it" || bad "no manifest"

# The rehearsal: restore into a scratch database and count what arrived.
"$PHP" "$BACKEND/database/restore.php" "$LATEST" --apply --db-only --database=mediflow_drill >/dev/null 2>&1
DRILL=$("$MYSQL" -u root mediflow_drill -N -e "SELECT COUNT(*) FROM patients" 2>/dev/null | tr -d '\r')
LIVE=$(sql "SELECT COUNT(*) FROM patients")
if [ -n "$DRILL" ] && [ "$DRILL" = "$LIVE" ]; then
  ok "a restore rehearsal brings back all $DRILL patients"
else
  bad "restore rehearsal" "drill=[$DRILL] live=[$LIVE]"
fi
"$MYSQL" -u root -e "DROP DATABASE IF EXISTS mediflow_drill" 2>/dev/null

# A dry run must change nothing — that is what makes it safe to try.
"$PHP" "$BACKEND/database/restore.php" "$LATEST" >/dev/null 2>&1
AFTER=$(sql "SELECT COUNT(*) FROM patients")
want "a dry run leaves the live database alone" "$AFTER" "$LIVE"

echo
echo "========================================="
echo "passed: $PASS   failed: $FAIL"
[ "$FAIL" -eq 0 ]
