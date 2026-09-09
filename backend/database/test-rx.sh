#!/usr/bin/env bash
# §5 digital prescription: the five fields, and the PDF that comes out.
set -uo pipefail
BASE="${BASE:-http://127.0.0.1:8000/api/v1}"
MYSQL="${MYSQL:-/c/xampp/mysql/bin/mysql.exe}"
DB="${DB:-mediflow}"
OUT="${OUT:-.}"
PASS=0; FAIL=0
# Sign-ins are rate limited per IP, and a suite that runs straight after
# another starts with a bucket somebody else filled. Clearing it first means a
# 429 in this suite is this suite's fault. (test-security.sh is the exception:
# it wants the limiter, and clears the bucket right before measuring it.)
reset_limits() { [ -x "$MYSQL" ] && "$MYSQL" -u root "$DB" -e "TRUNCATE TABLE rate_limits;" 2>/dev/null; }
reset_limits
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m  %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m  %s  %s\n' "$1" "${2:-}"; }
want() { if [ "$2" = "$3" ]; then ok "$1 -> $2"; else bad "$1" "got [$2] want [$3]"; fi; }
sql()  { "$MYSQL" -u root "$DB" -N -e "$1"; }

tok() {
  curl -s -X POST "$BASE/auth/login" -H 'Content-Type: application/json' \
    -d "{\"email\":\"$1\",\"password\":\"Password123\"}" \
    | grep -o '"access_token":"[^"]*"' | head -1 | cut -d'"' -f4
}

DOCTOR=$(tok doctor@clinic.test)
PATIENT=$(tok patient@demo.test)
[ -z "$DOCTOR" ] && { echo "cannot sign in as the doctor"; exit 1; }
D=(-H "Authorization: Bearer $DOCTOR" -H 'X-Organization-Id: 1' -H 'Content-Type: application/json')
P=(-H "Authorization: Bearer $PATIENT" -H 'Content-Type: application/json')

PID=$(sql "SELECT p.id FROM patients p JOIN users u ON u.id=p.user_id WHERE u.email='patient@demo.test'")
DOC=$(sql "SELECT d.id FROM doctors d JOIN users u ON u.id=d.user_id WHERE u.email='doctor@clinic.test'")

# An open consultation to prescribe against.
ENC=$(sql "SELECT id FROM encounters WHERE patient_id=$PID AND doctor_id=$DOC AND status='open' ORDER BY id DESC LIMIT 1")
if [ -z "$ENC" ]; then
  BUSY=$(sql "SELECT id FROM encounters WHERE doctor_id=$DOC AND status='open' LIMIT 1")
  [ -n "$BUSY" ] && sql "UPDATE encounters SET status='completed', completed_at=NOW() WHERE id=$BUSY"
  curl -s -o /dev/null -X POST "${D[@]}" "$BASE/encounters" \
    -d "{\"patient_id\":$PID,\"doctor_id\":$DOC,\"type\":\"outpatient\",\"chief_complaint\":\"Prescription test\"}"
  ENC=$(sql "SELECT id FROM encounters WHERE patient_id=$PID AND doctor_id=$DOC AND status='open' ORDER BY id DESC LIMIT 1")
fi
[ -z "$ENC" ] && { echo "could not open a consultation"; exit 1; }
echo "  (consultation $ENC)"

echo
echo "[1] The catalogue answers, with defaults to save typing"
MEDS=$(curl -s "${D[@]}" "$BASE/prescriptions/medications?q=para")
echo "$MEDS" | grep -q '"medications"' && ok "medicine search works" || bad "search failed" "$(echo "$MEDS" | head -c 200)"
echo "$MEDS" | grep -q 'default_dosage' && ok "and carries a default dosage" || bad "no defaults in the catalogue"

echo
echo "[2] All five fields are written, and come back"
BODY=$(cat <<JSON
{
  "encounter_id": $ENC,
  "general_advice": "Rest and plenty of fluids",
  "items": [
    {"medication_name":"Paracetamol 500mg","dosage":"1 tablet","frequency":"three times a day",
     "duration":"5 days","instructions":"After meals"},
    {"medication_name":"Amoxicillin 250mg","dosage":"1 capsule","frequency":"twice a day",
     "duration":"7 days","instructions":"Finish the whole course"}
  ]
}
JSON
)
R=$(curl -s -X POST "${D[@]}" "$BASE/prescriptions" -d "$BODY")
RX=$(echo "$R" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)
[ -n "$RX" ] && ok "prescription created (id $RX)" || bad "not created" "$(echo "$R" | head -c 300)"

for f in dosage frequency duration instructions; do
  V=$(sql "SELECT $f FROM prescription_items WHERE prescription_id=$RX ORDER BY id LIMIT 1")
  [ -n "$V" ] && ok "$f stored -> $V" || bad "$f is empty"
done
N=$(sql "SELECT COUNT(*) FROM prescription_items WHERE prescription_id=$RX")
want "both medicines saved" "$N" "2"

echo
echo "[3] Issuing locks it"
C=$(curl -s -o /dev/null -w '%{http_code}' -X POST "${D[@]}" "$BASE/prescriptions/$RX/issue")
want "issue" "$C" "200"
STATUS=$(sql "SELECT status FROM prescriptions WHERE id=$RX")
want "status" "$STATUS" "issued"
C=$(curl -s -o /dev/null -w '%{http_code}' -X PUT "${D[@]}" "$BASE/prescriptions/$RX" -d '{"advice":"changed"}')
if [ "$C" = "409" ] || [ "$C" = "422" ] || [ "$C" = "403" ]; then ok "an issued prescription cannot be edited -> $C"
else bad "an issued prescription was editable" "got $C"; fi

echo
echo "[4] The PDF is a real PDF, with the five fields in it"
curl -s "${D[@]}" "$BASE/prescriptions/$RX/pdf" -o "$OUT/prescription.pdf"
SIZE=$(wc -c < "$OUT/prescription.pdf" | tr -d ' ')
HEAD=$(head -c 4 "$OUT/prescription.pdf")
want "starts with %PDF" "$HEAD" "%PDF"
if [ "${SIZE:-0}" -gt 1000 ]; then ok "size is $SIZE bytes"; else bad "suspiciously small" "$SIZE bytes"; fi

# A PDF's page content is a compressed stream, so `strings` finds nothing in
# one that is perfectly fine. Inflate it and read the text operators instead.
TEXT=$("${PHP:-/c/xampp/php/php.exe}" "$(dirname "$0")/pdftext.php" "$OUT/prescription.pdf" 2>/dev/null)
for needle in Paracetamol Amoxicillin "1 tablet" "twice a day" "7 days" "After meals"; do
  if echo "$TEXT" | grep -qi -- "$needle"; then ok "the PDF says \"$needle\""
  else bad "\"$needle\" is missing from the PDF"; fi
done

echo
echo "[5] The patient gets their own copy"
if [ -n "$PATIENT" ]; then
  C=$(curl -s -o /dev/null -w '%{http_code}' "${P[@]}" "$BASE/patient/prescriptions/$RX/pdf")
  want "patient downloads it" "$C" "200"
  LIST=$(curl -s "${P[@]}" "$BASE/patient/prescriptions")
  echo "$LIST" | grep -q 'Paracetamol' && ok "and it is in their medicines list" || bad "not in the list"
else
  bad "could not sign in as the patient"
fi

echo
echo "[6] Somebody else's prescription is not downloadable"
OTHER=$(sql "SELECT p.id FROM prescriptions p JOIN encounters e ON e.id=p.encounter_id
             WHERE e.patient_id <> $PID ORDER BY p.id DESC LIMIT 1")
if [ -n "$OTHER" ]; then
  C=$(curl -s -o /dev/null -w '%{http_code}' "${P[@]}" "$BASE/patient/prescriptions/$OTHER/pdf")
  if [ "$C" = "403" ] || [ "$C" = "404" ]; then ok "another patient's PDF refused -> $C"
  else bad "another patient's PDF was served" "got $C"; fi
else
  ok "no other prescription to try"
fi

echo
echo "========================================="
echo "passed: $PASS   failed: $FAIL"
[ "$FAIL" -eq 0 ]
