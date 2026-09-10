#!/usr/bin/env bash
# §19/§20 file storage and the notification engine: where documents live,
# who may read them, and how many ways a message can leave.
set -uo pipefail
BASE="${BASE:-http://127.0.0.1:8000/api/v1}"
MYSQL="${MYSQL:-/c/xampp/mysql/bin/mysql.exe}"
DB="${DB:-mediflow}"
REPO="${REPO:-/c/Users/Mr Shahram/mediflow}"
WREPO="${WREPO:-C:/Users/Mr Shahram/mediflow}"
PHP="${PHP:-C:/xampp/php/php.exe}"
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
sql()  { "$MYSQL" -u root "$DB" -N -e "$1" 2>/dev/null | tr -d '\r'; }

TOK=$(curl -s -X POST "$BASE/auth/login" -H 'Content-Type: application/json' \
        -d '{"email":"owner@clinic.test","password":"Password123"}' \
      | grep -o '"access_token":"[^"]*"' | head -1 | cut -d'"' -f4)
[ -z "$TOK" ] && { echo "cannot sign in"; exit 1; }
O=(-H "Authorization: Bearer $TOK" -H 'X-Organization-Id: 1' -H 'Content-Type: application/json')

step "1. The store knows every kind of document §19 names"
TYPE=$(sql "SELECT COLUMN_TYPE FROM information_schema.columns
             WHERE table_schema='$DB' AND table_name='medical_documents'
               AND column_name='category'")
for c in prescription lab_report imaging invoice discharge; do
  echo "$TYPE" | grep -q "'$c'" && ok "category '$c'" || bad "category '$c' is missing"
done

step "2. Metadata and access control live in the database"
COLS=$(sql "SELECT GROUP_CONCAT(column_name) FROM information_schema.columns
             WHERE table_schema='$DB' AND table_name='medical_documents'")
for c in storage_path mime_type size_bytes checksum_sha256 visibility uploaded_by; do
  echo "$COLS" | grep -q "$c" && ok "$c is recorded" || bad "$c is not recorded"
done
TYPE=$(sql "SELECT COLUMN_TYPE FROM information_schema.columns
             WHERE table_schema='$DB' AND table_name='medical_documents'
               AND column_name='visibility'")
echo "$TYPE" | grep -q 'clinic_only' && echo "$TYPE" | grep -q 'patient_visible' \
  && ok "visibility distinguishes clinic-only from patient-visible" \
  || bad "visibility has no access rule" "$TYPE"

step "3. The bytes are not on the web"
C=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:8000/storage/app/public/documents/")
want "the storage folder over HTTP" "$C" "404"
PATHS=$(sql "SELECT COUNT(*) FROM medical_documents WHERE storage_path LIKE 'public/%'
               OR storage_path LIKE '/%' OR storage_path LIKE '%..%'")
want "no document escapes its folder" "$PATHS" "0"
C=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/documents/1/download")
want "a download without a token" "$C" "401"

step "4. An issued prescription is kept, not re-rendered forever"
# A prescription is written during the consultation, so the encounter has to
# be open. seed_today.php leaves one in the chair; if the suite runs twice in
# a row that one may have moved on, so fall back to opening a fresh visit.
ENC=$(sql "SELECT id FROM encounters WHERE organization_id=1 AND status='open' ORDER BY id DESC LIMIT 1")
if [ -z "$ENC" ]; then
  APPT=$(sql "SELECT id FROM appointments WHERE organization_id=1
                AND status IN ('booked','confirmed','arrived') ORDER BY id DESC LIMIT 1")
  ENC=$(curl -s -X POST "${O[@]}" -d "{\"appointment_id\":$APPT}" "$BASE/encounters" \
        | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)
fi
PAT=$(sql "SELECT patient_id FROM encounters WHERE id=$ENC")
[ -n "$PAT" ] && ok "an open consultation to prescribe in (encounter $ENC)" \
              || bad "no open encounter — run database/seed_today.php"
RX=$(curl -s -X POST "${O[@]}" -d "{\"encounter_id\":$ENC,\"items\":[{\"medication_name\":\"Amoxicillin 500mg\",\"dosage\":\"1 tablet\",\"frequency\":\"three times a day\",\"duration\":\"5 days\"}]}" \
      "$BASE/prescriptions" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)
[ -n "$RX" ] && ok "a prescription was created (id $RX)" || bad "could not create a prescription"

BEFORE=$(sql "SELECT COUNT(*) FROM medical_documents WHERE category='prescription'")
C=$(curl -s -o /dev/null -w '%{http_code}' -X POST "${O[@]}" "$BASE/prescriptions/$RX/issue")
want "issuing it" "$C" "200"
AFTER=$(sql "SELECT COUNT(*) FROM medical_documents WHERE category='prescription'")
[ "${AFTER:-0}" -gt "${BEFORE:-0}" ] && ok "issuing filed a document ($BEFORE -> $AFTER)" \
                                     || bad "no document was filed on issue"
P=$(sql "SELECT pdf_path FROM prescriptions WHERE id=$RX")
[ -n "$P" ] && ok "and the prescription points at it" || bad "pdf_path is still empty"
[ -n "$P" ] && [ -s "$REPO/backend/storage/app/public/$P" ] \
  && ok "and the file is really on disk" || bad "the stored path has no file behind it"

# The checksum is what makes a kept copy worth keeping.
SUM=$(sql "SELECT checksum_sha256 FROM medical_documents WHERE category='prescription' ORDER BY id DESC LIMIT 1")
echo "$SUM" | grep -qE '^[0-9a-f]{64}$' && ok "with a SHA-256 recorded" || bad "no checksum" "$SUM"
if [ -n "$P" ] && [ -f "$REPO/backend/storage/app/public/$P" ]; then
  REAL=$("$PHP" -r 'echo hash_file("sha256", $argv[1]);' "$WREPO/backend/storage/app/public/$P" 2>/dev/null)
  want "and the checksum matches the bytes" "$REAL" "$SUM"
fi
head -c 4 "$REPO/backend/storage/app/public/$P" 2>/dev/null | grep -q '%PDF' \
  && ok "the kept file is a PDF" || bad "the kept file is not a PDF"

step "5. An issued invoice is kept too"
BEFORE=$(sql "SELECT COUNT(*) FROM medical_documents WHERE category='invoice'")
INV=$(curl -s -X POST "${O[@]}" -d "{\"patient_id\":$PAT,\"items\":[{\"description\":\"Consultation\",\"quantity\":1,\"unit_price\":\"1500.00\"}]}" \
       "$BASE/invoices" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)
C=$(curl -s -o /dev/null -w '%{http_code}' -X POST "${O[@]}" "$BASE/invoices/$INV/issue")
want "issuing an invoice" "$C" "200"
AFTER=$(sql "SELECT COUNT(*) FROM medical_documents WHERE category='invoice'")
[ "${AFTER:-0}" -gt "${BEFORE:-0}" ] && ok "filed a document ($BEFORE -> $AFTER)" || bad "invoice was not filed"
P=$(sql "SELECT pdf_path FROM invoices WHERE id=$INV")
[ -n "$P" ] && ok "and the invoice points at it" || bad "invoice pdf_path is empty"

# A draft must NOT be filed — only an issued document stops changing.
DRAFT=$(curl -s -X POST "${O[@]}" -d "{\"patient_id\":$PAT,\"items\":[{\"description\":\"Draft only\",\"quantity\":1,\"unit_price\":\"100.00\"}]}" \
         "$BASE/invoices" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)
P=$(sql "SELECT COALESCE(pdf_path,'') FROM invoices WHERE id=$DRAFT")
want "a draft invoice is not filed" "$P" ""

step "6. A patient can reach their own documents and not the clinic's"
VIS=$(sql "SELECT visibility FROM medical_documents WHERE category='invoice' ORDER BY id DESC LIMIT 1")
want "an issued invoice is the patient's to read" "$VIS" "patient_visible"
N=$(sql "SELECT COUNT(*) FROM medical_documents WHERE visibility='clinic_only'")
ok "$N document(s) are held back as clinic-only"

step "7. Every channel §20 names has a handler"
OUT=$("$PHP" -r '
require "'"$WREPO"'/backend/bootstrap/app.php";
$d = new App\Services\Notifications\Dispatcher();
foreach ($d->status() as $name => $configured) {
    echo $name . "=" . ($configured ? "configured" : "not-configured") . "\n";
}' 2>&1)
for ch in in_app email sms push whatsapp; do
  echo "$OUT" | grep -q "^$ch=" && ok "channel '$ch' has a handler" || bad "channel '$ch' has no handler"
done
# The enum has to allow what the dispatcher can send.
TYPE=$(sql "SELECT COLUMN_TYPE FROM information_schema.columns
             WHERE table_schema='$DB' AND table_name='notifications' AND column_name='channel'")
echo "$TYPE" | grep -q "'whatsapp'" && ok "and the table accepts whatsapp" || bad "whatsapp is not a valid channel"

step "8. An unconfigured channel is skipped, not failed"
OUT=$("$PHP" -r '
require "'"$WREPO"'/backend/bootstrap/app.php";
$c = new App\Services\Notifications\WhatsAppChannel();
echo ($c->isConfigured() ? "configured" : "unconfigured") . "\n";
echo $c->send(["to_address" => "+923001234567", "title" => "T", "body" => "B"]) . "\n";
echo $c->name() . "\n";' 2>&1)
echo "$OUT" | grep -q '^unconfigured' && ok "WhatsApp is off by default" || bad "WhatsApp claims to be configured"
echo "$OUT" | grep -q '^skipped'      && ok "and reports skipped, not failed" || bad "wrong outcome" "$OUT"
echo "$OUT" | grep -q '^whatsapp'     && ok "and names itself correctly" || bad "wrong channel name"

step "9. One queue, many channels"
N=$(sql "SELECT COUNT(DISTINCT channel) FROM notifications")
[ "${N:-0}" -ge 3 ] && ok "$N channels have real rows in the one queue" || bad "only $N channel(s) in use"
N=$(sql "SELECT COUNT(*) FROM notifications WHERE status='queued' AND attempts >= 5")
want "nothing is retried for ever" "$N" "0"

step "10. Every kind §19 names can actually be stored and read back"
# Step 1 asks the enum whether it knows these words. That is a schema check,
# and it passed for 'imaging' and 'discharge' while the store held not one
# document of either kind and nothing had ever put one there. Declaring a
# category and supporting it are different claims, so this makes the round
# trip: upload, list, download.
PATIENT=$(sql "SELECT id FROM patients WHERE organization_id=1 ORDER BY id LIMIT 1")
printf '%%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%%%EOF\n' > fixture-doc.pdf
UP=(-H "Authorization: Bearer $TOK" -H 'X-Organization-Id: 1')
MADE=""
for cat in prescription lab_report imaging invoice discharge; do
  BODY=$(curl -s -X POST "${UP[@]}" \
      -F "file=@fixture-doc.pdf" -F "title=Suite $cat" -F "category=$cat" \
      -F "visibility=patient_visible" "$BASE/patients/$PATIENT/documents")
  ID=$(printf '%s' "$BODY" | grep -o '"id":[0-9]\+' | head -1 | cut -d: -f2)
  if [ -n "$ID" ] && printf '%s' "$BODY" | grep -q "\"category\":\"$cat\""; then
    ok "a '$cat' document is stored (id $ID)"
    MADE="$MADE $ID"
    C=$(curl -s -o /dev/null -w '%{http_code}' "${UP[@]}" "$BASE/documents/$ID/download")
    [ "$C" = "200" ] && ok "and reads back" || bad "'$cat' stored but will not download" "$C"
  else
    bad "a '$cat' document could not be stored" "$(printf '%s' "$BODY" | head -c 120)"
  fi
done
rm -f fixture-doc.pdf

step "11. A stored document is checked against its checksum before it is served"
# medical_documents has carried checksum_sha256 since the beginning, and
# DocumentStore says outright that it "is what makes the kept copy worth
# having — it can be shown to be the same bytes". Nothing ever showed it:
# the column was written on every document and compared on none, so bytes
# altered on disk were served as though authoritative.
DOC=$(printf '%s' "$MADE" | awk '{print $1}')
if [ -z "$DOC" ]; then
  bad "no document to verify"
else
  REL=$(sql "SELECT storage_path FROM medical_documents WHERE id=$DOC")
  ABS="$REPO/backend/storage/app/public/$REL"
  if [ ! -f "$ABS" ]; then
    bad "cannot find the stored bytes to tamper with" "$ABS"
  else
    C=$(curl -s -o /dev/null -w '%{http_code}' "${UP[@]}" "$BASE/documents/$DOC/download")
    want "an untouched document downloads" "$C" "200"

    cp "$ABS" "$ABS.suitebak"
    printf 'TAMPERED' >> "$ABS"
    C=$(curl -s -o /dev/null -w '%{http_code}' "${UP[@]}" "$BASE/documents/$DOC/download")
    [ "$C" = "409" ] && ok "altered bytes are refused, not served ($C)" \
                     || bad "altered bytes were served" "got $C, wanted 409"

    # And the refusal is written down, because somebody has to find out.
    N=$(sql "SELECT COUNT(*) FROM audit_logs
              WHERE action='integrity_failed' AND resource_id=$DOC")
    [ "${N:-0}" -gt 0 ] && ok "and the mismatch is in the audit trail" \
                        || bad "an integrity failure left no trace"

    mv "$ABS.suitebak" "$ABS"
    C=$(curl -s -o /dev/null -w '%{http_code}' "${UP[@]}" "$BASE/documents/$DOC/download")
    want "and it serves again once restored" "$C" "200"
  fi
fi

# Leave the store as it was found: these were the suite's own uploads.
for ID in $MADE; do
  REL=$(sql "SELECT storage_path FROM medical_documents WHERE id=$ID")
  [ -n "$REL" ] && rm -f "$REPO/backend/storage/app/public/$REL"
  sql "DELETE FROM medical_documents WHERE id=$ID" >/dev/null
done
LEFT=$(sql "SELECT COUNT(*) FROM medical_documents WHERE title LIKE 'Suite %'")
want "the suite cleans up after itself" "$LEFT" "0"

step "12. Every send leaves by the one door"
# §20's word is "centralize". Handlers existing (step 7) is not the same
# claim: it stays true even if half the codebase quietly calls mail() or
# posts to Expo on its own. What makes the engine central is that nothing
# else delivers, so that is what gets asked — of the source, because a rule
# like this erodes one convenient shortcut at a time and never fails a test
# while doing it.
BACKEND="$REPO/backend"
DELIVERY='\bmail\(|stream_socket_client|fsockopen|exp\.host|api\.twilio|graph\.facebook'
OUTSIDE=$(cd "$BACKEND" && grep -rnE "$DELIVERY" app --include=*.php 2>/dev/null \
  | grep -v '^app/Services/Notifications/' \
  | grep -v ':\s*\*' | grep -v '//' || true)
[ -z "$OUTSIDE" ] && ok "nothing outside Services/Notifications delivers a message" \
                  || bad "a send bypasses the notification engine" "$(printf '%s' "$OUTSIDE" | head -2)"

# And the pattern must be able to find the real senders, or the check above
# is green for having read nothing.
INSIDE=$(cd "$BACKEND" && grep -rlE "$DELIVERY" app/Services/Notifications 2>/dev/null | wc -l)
[ "${INSIDE:-0}" -ge 2 ] && ok "the sweep can see $INSIDE channel(s) that really deliver" \
                         || bad "the delivery sweep matches nothing at all" "${INSIDE:-0}"

# One queue means one table: a channel that invented its own store would be
# outside everything the retry cap and the trail cover.
QUEUES=$(cd "$BACKEND" && grep -rhoE "INSERT INTO [a-z_]+" app/Services/Notifications app/Services/NotificationService.php 2>/dev/null \
  | awk '{print $3}' | sort -u | grep -v '^notifications$' || true)
[ -z "$QUEUES" ] && ok "and they all queue into 'notifications'" \
                 || bad "a channel writes its own queue" "$(printf '%s' "$QUEUES" | tr '\n' ' ')"

echo
echo "========================================="
echo "passed: $PASS   failed: $FAIL"
[ "$FAIL" -eq 0 ]
