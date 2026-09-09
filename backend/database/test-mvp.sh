#!/usr/bin/env bash
# The killer MVP workflow, end to end, exactly as the document describes it:
#
#   doctor signs in -> opens the appointment -> consults and diagnoses
#   -> prescribes and bills the services -> invoice -> payment -> receipt
#   -> and the patient sees it on their phone
#
# Every step is checked from the outside, through the API, in one run — the
# point of this file is not that each piece works (they have their own tests)
# but that the chain holds together and reaches the patient's app.
set -uo pipefail
BASE="${BASE:-http://127.0.0.1:8000/api/v1}"
MYSQL="${MYSQL:-/c/xampp/mysql/bin/mysql.exe}"
DB="${DB:-mediflow}"
PASS=0; FAIL=0
step() { printf '\n\033[1m%s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m  %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m  %s  %s\n' "$1" "${2:-}"; }
want() { if [ "$2" = "$3" ]; then ok "$1 -> $2"; else bad "$1" "got [$2] want [$3]"; fi; }
sql()  { "$MYSQL" -u root "$DB" -N -e "$1"; }
id_of(){ echo "$1" | grep -o "\"$2\":[0-9]*" | head -1 | cut -d: -f2; }

# ---------------------------------------------------------------
step "1. The doctor signs in"

LOGIN=$(curl -s -X POST "$BASE/auth/login" -H 'Content-Type: application/json' \
  -d '{"email":"doctor@clinic.test","password":"Password123"}')
DOCTOR=$(echo "$LOGIN" | grep -o '"access_token":"[^"]*"' | head -1 | cut -d'"' -f4)
[ -n "$DOCTOR" ] && ok "signed in" || { bad "cannot sign in" "$(echo "$LOGIN" | head -c 200)"; exit 1; }
D=(-H "Authorization: Bearer $DOCTOR" -H 'X-Organization-Id: 1' -H 'Content-Type: application/json')

DASH=$(curl -s "${D[@]}" "$BASE/doctors/dashboard")
echo "$DASH" | grep -q '"counts"' && ok "the dashboard loads" || bad "no dashboard" "$(echo "$DASH" | head -c 200)"

PID=$(sql "SELECT p.id FROM patients p JOIN users u ON u.id=p.user_id WHERE u.email='patient@demo.test'")
DOC=$(sql "SELECT d.id FROM doctors d JOIN users u ON u.id=d.user_id WHERE u.email='doctor@clinic.test'")

# ---------------------------------------------------------------
step "2. He opens today's appointment for the patient"

sql "UPDATE encounters SET status='completed', completed_at=NOW() WHERE doctor_id=$DOC AND status='open'" >/dev/null
APPT=$(sql "SELECT id FROM appointments WHERE doctor_id=$DOC AND patient_id=$PID
            AND DATE(scheduled_at)=CURDATE() AND status IN ('booked','confirmed','arrived')
            ORDER BY id LIMIT 1")
if [ -z "$APPT" ]; then
  echo "  (no appointment today — run database/seed_today.php)"; exit 1
fi
ok "found today's appointment (id $APPT)"

R=$(curl -s -X POST "${D[@]}" "$BASE/encounters" \
      -d "{\"patient_id\":$PID,\"doctor_id\":$DOC,\"appointment_id\":$APPT,\"type\":\"outpatient\",\"chief_complaint\":\"Toothache, left side, four days\"}")
ENC=$(id_of "$R" id)
[ -n "$ENC" ] && ok "consultation opened (id $ENC)" || bad "could not open" "$(echo "$R" | head -c 300)"

# ---------------------------------------------------------------
step "3. He examines and diagnoses"

C=$(curl -s -o /dev/null -w '%{http_code}' -X PUT "${D[@]}" "$BASE/encounters/$ENC" -d '{
  "symptoms":"Sharp pain on cold, worse at night",
  "examination":"Deep caries upper left first molar, percussion tender",
  "bp_systolic":118,"bp_diastolic":76,"pulse":72,"temperature_c":36.8}')
want "findings saved" "$C" "200"

C=$(curl -s -o /dev/null -w '%{http_code}' -X POST "${D[@]}" "$BASE/encounters/$ENC/diagnoses" \
      -d '{"description":"Irreversible pulpitis, tooth 26","icd10_code":"K04.0","type":"primary"}')
want "diagnosis recorded" "$C" "201"

N=$(sql "SELECT COUNT(*) FROM diagnoses WHERE encounter_id=$ENC")
want "it is on the encounter" "$N" "1"

# ---------------------------------------------------------------
step "4. He prescribes, and records the work done"

BODY=$(cat <<JSON
{"encounter_id": $ENC, "general_advice": "Avoid cold drinks until the root canal is finished",
 "items":[
   {"medication_name":"Ibuprofen 400mg","dosage":"1 tablet","frequency":"three times a day",
    "duration":"3 days","instructions":"After food"},
   {"medication_name":"Amoxicillin 500mg","dosage":"1 capsule","frequency":"three times a day",
    "duration":"5 days","instructions":"Finish the course"}]}
JSON
)
R=$(curl -s -X POST "${D[@]}" "$BASE/prescriptions" -d "$BODY")
RX=$(id_of "$R" id)
[ -n "$RX" ] && ok "prescription written (id $RX)" || bad "no prescription" "$(echo "$R" | head -c 300)"

C=$(curl -s -o /dev/null -w '%{http_code}' -X POST "${D[@]}" "$BASE/prescriptions/$RX/issue")
want "prescription issued" "$C" "200"

# A billable service — this is the "select services" step.
SVC=$(sql "SELECT code FROM services WHERE organization_id=1 AND is_active=1 ORDER BY id LIMIT 1")
C=$(curl -s -o /dev/null -w '%{http_code}' -X POST "${D[@]}" "$BASE/encounters/$ENC/procedures" \
      -d "{\"name\":\"Root canal, first visit\",\"service_code\":\"$SVC\",\"notes\":\"Pulp extirpated, dressing placed\"}")
if [ "$C" = "201" ] || [ "$C" = "200" ]; then ok "procedure recorded -> $C"; else bad "procedure failed" "got $C"; fi

# ---------------------------------------------------------------
step "5. The visit is completed, billed, and paid"

C=$(curl -s -o /dev/null -w '%{http_code}' -X POST "${D[@]}" "$BASE/encounters/$ENC/complete" \
      -d '{"followup_on":"'"$(date -d '+14 days' +%Y-%m-%d 2>/dev/null || date +%Y-%m-%d)"'"}')
want "consultation completed" "$C" "200"

R=$(curl -s -X POST "${D[@]}" "$BASE/encounters/$ENC/invoice" -d '{}')
INV=$(id_of "$R" id)
[ -n "$INV" ] && ok "invoice drafted from the visit (id $INV)" || bad "no invoice" "$(echo "$R" | head -c 300)"

C=$(curl -s -o /dev/null -w '%{http_code}' -X POST "${D[@]}" "$BASE/invoices/$INV/issue" -d '{}')
want "invoice issued" "$C" "200"

TOTAL=$(sql "SELECT ROUND(grand_total,2) FROM invoices WHERE id=$INV")
NO=$(sql "SELECT invoice_no FROM invoices WHERE id=$INV")
ok "it totals $TOTAL ($NO)"

R=$(curl -s -X POST "${D[@]}" "$BASE/invoices/$INV/payments" \
      -d "{\"amount\":$TOTAL,\"method\":\"cash\"}")
RECEIPT=$(echo "$R" | grep -o '"receipt_no":"[^"]*"' | head -1 | cut -d'"' -f4)
[ -n "$RECEIPT" ] && ok "payment taken, receipt $RECEIPT" || bad "no receipt" "$(echo "$R" | head -c 300)"

want "the invoice is settled" "$(sql "SELECT ROUND(grand_total - paid_total,2) FROM invoices WHERE id=$INV")" "0.00"
want "and reads as paid"      "$(sql "SELECT status FROM invoices WHERE id=$INV")" "paid"

# ---------------------------------------------------------------
step "6. The patient sees all of it on their phone"

PTOK=$(curl -s -X POST "$BASE/auth/login" -H 'Content-Type: application/json' \
  -d '{"email":"patient@demo.test","password":"Password123"}' \
  | grep -o '"access_token":"[^"]*"' | head -1 | cut -d'"' -f4)
P=(-H "Authorization: Bearer $PTOK" -H 'Content-Type: application/json')

DASH=$(curl -s "${P[@]}" "$BASE/patient/dashboard")
echo "$DASH" | grep -q '"health_summary"' && ok "their dashboard loads" || bad "no patient dashboard"

RECS=$(curl -s "${P[@]}" "$BASE/patient/records")
echo "$RECS" | grep -q 'Irreversible pulpitis' && ok "the diagnosis is in their records" \
  || bad "diagnosis not visible to the patient"

MEDS=$(curl -s "${P[@]}" "$BASE/patient/prescriptions")
echo "$MEDS" | grep -q 'Ibuprofen 400mg' && ok "the medicines are listed" || bad "medicines not visible"

C=$(curl -s -o /dev/null -w '%{http_code}' "${P[@]}" "$BASE/patient/prescriptions/$RX/pdf")
want "and the prescription PDF opens" "$C" "200"

BILLS=$(curl -s "${P[@]}" "$BASE/patient/bills")
echo "$BILLS" | grep -q "$NO" && ok "the invoice is on their bills screen" || bad "invoice not visible"
echo "$BILLS" | grep -q "$RECEIPT" && ok "so is the receipt" || bad "receipt not visible"
OUTSTANDING=$(echo "$BILLS" | grep -o '"outstanding":"[^"]*"' | head -1 | cut -d'"' -f4)
ok "their outstanding balance now reads $OUTSTANDING"

INBOX=$(curl -s "${P[@]}" "$BASE/patient/notifications")
echo "$INBOX" | grep -q 'prescription.issued' && ok "they were told about the prescription" \
  || bad "no prescription notification"
echo "$INBOX" | grep -q 'invoice.issued'      && ok "and about the invoice" || bad "no invoice notification"
echo "$INBOX" | grep -q 'payment.received'    && ok "and that the payment landed" || bad "no payment notification"

echo
echo "========================================="
echo "passed: $PASS   failed: $FAIL"
[ "$FAIL" -eq 0 ]
