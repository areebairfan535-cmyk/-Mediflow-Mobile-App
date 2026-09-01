#!/usr/bin/env bash
# Phase 2 end-to-end test — the §4 consultation workflow, plus the rules
# that protect it.
#
#   bash database/smoke-test-clinical.sh
#
# Requires the API on :8000, seed.php and seed_clinical.php already run.

set -uo pipefail

BASE="${BASE:-http://127.0.0.1:8000/api/v1}"
MYSQL="${MYSQL:-/c/xampp/mysql/bin/mysql.exe}"
DB="${DB:-mediflow}"
PASS=0
FAIL=0

pass() { PASS=$((PASS + 1)); printf '  \033[32mPASS\033[0m  %s\n' "$1"; }
fail() { FAIL=$((FAIL + 1)); printf '  \033[31mFAIL\033[0m  %s\n' "$1"; [ -n "${2:-}" ] && printf '        %s\n' "$2"; }
expect() { if [ "$2" = "$3" ]; then pass "$1 -> $2"; else fail "$1 -> got $2, want $3" "${4:-}"; fi; }

reset_limits() {
  [ -x "$MYSQL" ] && "$MYSQL" -u root "$DB" -e "TRUNCATE TABLE rate_limits;" 2>/dev/null
}

# A doctor may hold only one open consultation at a time (sec 4). A run that
# dies part-way therefore leaves the next run unable to start one at all, and
# every assertion after that fails for a reason unrelated to the code under
# test. Close whatever is still open before starting.
close_stale_encounters() {
  [ -x "$MYSQL" ] && "$MYSQL" -u root "$DB" -e \
    "UPDATE encounters SET status = 'cancelled', updated_at = UTC_TIMESTAMP()
       WHERE status = 'open';
     UPDATE appointments SET status = 'cancelled', updated_at = UTC_TIMESTAMP()
       WHERE status = 'in_consultation';" 2>/dev/null
}

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
jval() { printf '%s' "$1" | grep -o "\"$2\":\"[^\"]*\"" | head -1 | sed "s/.*\"$2\":\"//; s/\"$//"; }
jnum() { printf '%s' "$1" | grep -o "\"$2\":[0-9]*" | head -1 | sed "s/.*://"; }

reset_limits
close_stale_encounters

echo
echo "MediFlow Phase 2 — clinical smoke test"
echo "======================================"

# ---------------------------------------------------------------
echo
echo "[setup] sign in"

R=$(api POST /auth/login '{"email":"doctor@clinic.test","password":"Password123"}')
expect "doctor login" "$(status_of "$R")" "200"
DOC=$(jval "$(body_of "$R")" access_token)
AUTH=(-H "Authorization: Bearer $DOC")

R=$(api POST /auth/login '{"email":"reception@clinic.test","password":"Password123"}')
RECEPTION=$(jval "$(body_of "$R")" access_token)
RAUTH=(-H "Authorization: Bearer $RECEPTION")

R=$(api POST /auth/login '{"email":"owner@clinic.test","password":"Password123"}')
OWNER=$(jval "$(body_of "$R")" access_token)
OAUTH=(-H "Authorization: Bearer $OWNER")

# ---------------------------------------------------------------
echo
echo "[1] Patients"

R=$(api GET /patients '' "${AUTH[@]}")
expect "list patients" "$(status_of "$R")" "200"

STAMP=$(date +%s)
R=$(api POST /patients "{\"first_name\":\"Test\",\"last_name\":\"Patient$STAMP\",\"date_of_birth\":\"1990-01-15\",\"gender\":\"male\",\"phone\":\"0300-$STAMP\"}" "${AUTH[@]}")
expect "register patient" "$(status_of "$R")" "201"
PATIENT=$(jnum "$(body_of "$R")" id)
MRN=$(jval "$(body_of "$R")" mrn)
[ -n "$MRN" ] && pass "MRN auto-assigned ($MRN)" || fail "no MRN assigned"

# Same phone twice is the same person being registered twice.
R=$(api POST /patients "{\"first_name\":\"Dup\",\"last_name\":\"Person\",\"phone\":\"0300-$STAMP\"}" "${AUTH[@]}")
expect "duplicate phone rejected" "$(status_of "$R")" "409"

R=$(api GET "/patients/$PATIENT" '' "${AUTH[@]}")
expect "patient chart loads" "$(status_of "$R")" "200"
case "$(body_of "$R")" in
  *'"allergies"'*) pass "chart includes allergies block" ;;
  *)               fail "chart missing allergies" ;;
esac

R=$(api GET "/patients?search=Patient$STAMP" '' "${AUTH[@]}")
case "$(body_of "$R")" in
  *"Patient$STAMP"*) pass "search finds the new patient" ;;
  *)                 fail "search did not find the patient" ;;
esac

R=$(api POST "/patients/$PATIENT/allergies" '{"substance":"Penicillin","reaction":"Rash","severity":"severe"}' "${AUTH[@]}")
expect "record allergy" "$(status_of "$R")" "201"

# ---------------------------------------------------------------
echo
echo "[2] Doctors & availability"

R=$(api GET /doctors '' "${AUTH[@]}")
expect "list doctors" "$(status_of "$R")" "200"
DOCTOR=$(jnum "$(body_of "$R")" id)

R=$(api GET "/doctors/$DOCTOR/schedule" '' "${AUTH[@]}")
expect "read weekly schedule" "$(status_of "$R")" "200"

# UTC, because the API answers in UTC. On a machine west of Greenwich a
# local "+1 day" is still today in UTC, which would put the out-of-hours
# booking below in the past and turn its 409 into a 422.
# The next day the clinic actually sits — a fixed "+1 day" lands on a Sunday
# once a week, and the whole section then fails for a reason that has nothing
# to do with the code under test. The out-of-hours check below still needs a
# day the doctor works, so 03:00 on it is genuinely outside their hours.
TOMORROW=""
for OFFSET in 1 2 3 4 5 6 7; do
  CANDIDATE=$(date -u -d "+$OFFSET day" +%Y-%m-%d 2>/dev/null || date -u -v+"$OFFSET"d +%Y-%m-%d)
  R=$(api GET "/doctors/$DOCTOR/available-slots?date=$CANDIDATE" '' "${AUTH[@]}")
  case "$(body_of "$R")" in
    *'"start"'*) TOMORROW="$CANDIDATE"; break ;;
  esac
done
[ -n "$TOMORROW" ] || TOMORROW=$(date -u -d "+1 day" +%Y-%m-%d)

R=$(api GET "/doctors/$DOCTOR/available-slots?date=$TOMORROW" '' "${AUTH[@]}")
expect "free slots for tomorrow" "$(status_of "$R")" "200"
SLOT=$(printf '%s' "$(body_of "$R")" | grep -o '"start":"[^"]*"' | head -1 | sed 's/.*"start":"//; s/"$//')
[ -n "$SLOT" ] && pass "slot offered ($SLOT)" || fail "no slots returned for $TOMORROW"

# /doctors/dashboard must not be swallowed by /doctors/{id}
R=$(api GET /doctors/dashboard '' "${AUTH[@]}")
expect "static route beats {id} pattern" "$(status_of "$R")" "200"

# ---------------------------------------------------------------
echo
echo "[3] Appointments"

R=$(api POST /appointments "{\"patient_id\":$PATIENT,\"doctor_id\":$DOCTOR,\"scheduled_at\":\"$SLOT\",\"reason\":\"Smoke test visit\"}" "${AUTH[@]}")
expect "book appointment" "$(status_of "$R")" "201"
APPT=$(jnum "$(body_of "$R")" id)

# Same doctor, same instant.
R=$(api POST /appointments "{\"patient_id\":$PATIENT,\"doctor_id\":$DOCTOR,\"scheduled_at\":\"$SLOT\"}" "${AUTH[@]}")
expect "double-booking blocked" "$(status_of "$R")" "409"

# Overlap, not just exact equality. The doctor books in 15-minute slots, so
# +5 minutes lands INSIDE the booking just made; +15 would be the next free
# slot and must stay bookable.
# $SLOT is UTC (that is what /available-slots returns), so shift it in UTC too.
OVERLAP=$(date -u -d "$SLOT UTC +5 minutes" "+%Y-%m-%d %H:%M:%S" 2>/dev/null || echo "")
if [ -n "$OVERLAP" ]; then
  R=$(api POST /appointments "{\"patient_id\":$PATIENT,\"doctor_id\":$DOCTOR,\"scheduled_at\":\"$OVERLAP\"}" "${AUTH[@]}")
  expect "overlapping slot blocked" "$(status_of "$R")" "409"
fi

R=$(api POST /appointments "{\"patient_id\":$PATIENT,\"doctor_id\":$DOCTOR,\"scheduled_at\":\"2020-01-01 10:00:00\"}" "${AUTH[@]}")
expect "past date rejected" "$(status_of "$R")" "422"

R=$(api POST /appointments "{\"patient_id\":$PATIENT,\"doctor_id\":$DOCTOR,\"scheduled_at\":\"$TOMORROW 03:00:00\"}" "${AUTH[@]}")
expect "outside working hours rejected" "$(status_of "$R")" "409"

# booked -> completed skips arrived/in_consultation.
R=$(api PUT "/appointments/$APPT/status" '{"status":"completed"}' "${AUTH[@]}")
expect "illegal status jump blocked" "$(status_of "$R")" "409"

R=$(api PUT "/appointments/$APPT/status" '{"status":"confirmed"}' "${AUTH[@]}")
expect "booked -> confirmed" "$(status_of "$R")" "200"
R=$(api PUT "/appointments/$APPT/status" '{"status":"arrived"}' "${AUTH[@]}")
expect "confirmed -> arrived" "$(status_of "$R")" "200"

# ---------------------------------------------------------------
echo
echo "[4] Consultation workflow (sec 4)"

R=$(api POST /encounters "{\"appointment_id\":$APPT}" "${AUTH[@]}")
expect "start consultation from appointment" "$(status_of "$R")" "201"
ENC=$(jnum "$(body_of "$R")" id)

R=$(api GET "/appointments/$APPT" '' "${AUTH[@]}")
case "$(body_of "$R")" in
  *'"status":"in_consultation"'*) pass "appointment moved to in_consultation" ;;
  *)                              fail "appointment status did not follow the encounter" ;;
esac

R=$(api POST /encounters "{\"appointment_id\":$APPT}" "${AUTH[@]}")
expect "second consultation on same appointment blocked" "$(status_of "$R")" "409"

R=$(api PUT "/encounters/$ENC" '{"symptoms":"Pain on chewing, 3 days","examination":"Tender #26, no swelling","bp_systolic":120,"bp_diastolic":80,"pulse":76,"temperature_c":36.8}' "${AUTH[@]}")
expect "record symptoms and vitals" "$(status_of "$R")" "200"

R=$(api PUT "/encounters/$ENC" '{"bp_systolic":400}' "${AUTH[@]}")
expect "impossible vital rejected" "$(status_of "$R")" "422"

R=$(api POST "/encounters/$ENC/diagnoses" '{"description":"Irreversible pulpitis #26","icd10_code":"K04.0","type":"primary"}' "${AUTH[@]}")
expect "record diagnosis" "$(status_of "$R")" "201"

R=$(api POST "/encounters/$ENC/procedures" '{"name":"Pulpotomy","site":"#26","outcome":"Uneventful"}' "${AUTH[@]}")
expect "record procedure" "$(status_of "$R")" "201"

R=$(api POST "/encounters/$ENC/lab-orders" '{"priority":"routine","clinical_notes":"Pre-op bloods"}' "${AUTH[@]}")
expect "order lab test" "$(status_of "$R")" "201"
LAB=$(jnum "$(body_of "$R")" id)

# ---------------------------------------------------------------
echo
echo "[5] Prescriptions (sec 4)"

R=$(api GET '/prescriptions/medications?search=amox' '' "${AUTH[@]}")
expect "medication catalogue search" "$(status_of "$R")" "200"
case "$(body_of "$R")" in
  *Amoxicillin*) pass "catalogue returns Amoxicillin with defaults" ;;
  *)             fail "catalogue search found nothing" ;;
esac

R=$(api POST /prescriptions "{\"encounter_id\":$ENC,\"general_advice\":\"Soft diet for 3 days\",\"items\":[{\"medication_name\":\"Paracetamol\",\"dosage\":\"1 tablet\",\"frequency\":\"every 6 hours\",\"duration\":\"5 days\"}]}" "${AUTH[@]}")
expect "create prescription" "$(status_of "$R")" "201"
RX=$(jnum "$(body_of "$R")" id)

R=$(api POST /prescriptions "{\"encounter_id\":$ENC,\"items\":[]}" "${AUTH[@]}")
expect "empty prescription rejected" "$(status_of "$R")" "422"

# The patient has a recorded Penicillin allergy; prescribing it must warn.
R=$(api PUT "/prescriptions/$RX" '{"items":[{"medication_name":"Penicillin V","dosage":"250mg","frequency":"four times a day","duration":"7 days"}]}' "${AUTH[@]}")
expect "prescribe against a known allergy" "$(status_of "$R")" "200"
case "$(body_of "$R")" in
  *ALLERGY*) pass "allergy warning returned (advisory, not blocking)" ;;
  *)         fail "no allergy warning for a known allergen" "$(body_of "$R")" ;;
esac

R=$(api POST "/prescriptions/$RX/issue" '' "${AUTH[@]}")
expect "issue prescription" "$(status_of "$R")" "200"

R=$(api PUT "/prescriptions/$RX" '{"general_advice":"changed"}' "${AUTH[@]}")
expect "issued prescription is immutable" "$(status_of "$R")" "409"

# ---------------------------------------------------------------
echo
echo "[6] Completing the visit"

R=$(api POST "/encounters/$ENC/complete" "{\"followup_on\":\"$TOMORROW\"}" "${AUTH[@]}")
expect "complete consultation" "$(status_of "$R")" "200"

R=$(api GET "/appointments/$APPT" '' "${AUTH[@]}")
case "$(body_of "$R")" in
  *'"status":"completed"'*) pass "appointment completed with the encounter" ;;
  *)                        fail "appointment did not complete" ;;
esac

R=$(api PUT "/encounters/$ENC" '{"symptoms":"late edit"}' "${AUTH[@]}")
expect "completed chart is read-only" "$(status_of "$R")" "409"

R=$(api POST "/encounters/$ENC/diagnoses" '{"description":"late diagnosis"}' "${AUTH[@]}")
expect "no diagnoses after completion" "$(status_of "$R")" "409"

# An empty visit must not become a billable record.
R=$(api POST /encounters "{\"patient_id\":$PATIENT,\"doctor_id\":$DOCTOR,\"chief_complaint\":\"Empty visit\"}" "${AUTH[@]}")
expect "start walk-in consultation" "$(status_of "$R")" "201"
EMPTY=$(jnum "$(body_of "$R")" id)
R=$(api POST "/encounters/$EMPTY/complete" '' "${AUTH[@]}")
expect "empty consultation cannot complete" "$(status_of "$R")" "409"

R=$(api POST /encounters "{\"patient_id\":$PATIENT,\"doctor_id\":$DOCTOR}" "${AUTH[@]}")
expect "second open consultation blocked" "$(status_of "$R")" "409"

R=$(api POST "/encounters/$EMPTY/cancel" '{"reason":"test cleanup"}' "${AUTH[@]}")
expect "cancel the empty consultation" "$(status_of "$R")" "200"

# ---------------------------------------------------------------
echo
echo "[7] Labs"

R=$(api POST "/lab-orders/$LAB/results" '{"results":[{"test_name":"Haemoglobin","value":"13.4","unit":"g/dL","reference_range":"12-16","flag":"normal"}]}' "${OAUTH[@]}")
expect "record lab result" "$(status_of "$R")" "200"

R=$(api POST "/lab-orders/$LAB/results" '{"results":[{"test_name":"Repeat","value":"1"}]}' "${OAUTH[@]}")
expect "results cannot be recorded twice" "$(status_of "$R")" "409"

# ---------------------------------------------------------------
echo
echo "[8] Permissions (sec 11)"

R=$(api POST "/encounters/$ENC/diagnoses" '{"description":"Receptionist diagnosis"}' "${RAUTH[@]}")
expect "receptionist cannot diagnose" "$(status_of "$R")" "403"

R=$(api POST /prescriptions "{\"encounter_id\":$ENC,\"items\":[{\"medication_name\":\"X\"}]}" "${RAUTH[@]}")
expect "receptionist cannot prescribe" "$(status_of "$R")" "403"

R=$(api POST /patients "{\"first_name\":\"Front\",\"last_name\":\"Desk$STAMP\",\"phone\":\"0311-$STAMP\"}" "${RAUTH[@]}")
expect "receptionist CAN register a patient" "$(status_of "$R")" "201"

R=$(api GET /appointments '' "${RAUTH[@]}")
expect "receptionist CAN see the calendar" "$(status_of "$R")" "200"

# ---------------------------------------------------------------
echo
echo "[9] Audit trail covers clinical access (sec 16)"

R=$(api GET "/audit-logs/patient/$PATIENT" '' "${OAUTH[@]}")
expect "patient access trail" "$(status_of "$R")" "200"
case "$(body_of "$R")" in
  *'"resource_type":"patient"'*) pass "record views are audited" ;;
  *)                             fail "patient views not in the trail" ;;
esac
case "$(body_of "$R")" in
  *prescription*) pass "prescription events audited against the patient" ;;
  *)              fail "prescription events missing from patient trail" ;;
esac

# ---------------------------------------------------------------
echo
echo "======================================"
printf 'passed: %d   failed: %d\n\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ] || exit 1
