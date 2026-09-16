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

# sec 4 minimise-typing: the diagnosis just recorded must come back as a
# suggestion, so the next doctor picks it instead of retyping the wording.
R=$(api GET '/diagnoses/common?search=pulpitis' '' "${AUTH[@]}")
expect "the clinic's own diagnoses come back" "$(status_of "$R")" "200"
B=$(body_of "$R")
case "$B" in
  *'Irreversible pulpitis #26'*) pass "the one just written is on the list" ;;
  *)                             fail "a diagnosis the clinic uses is not suggested" ;;
esac
case "$B" in
  *'"times_used"'*) pass "and it says how often it has been used" ;;
  *)                fail "no usage count to order the list by" ;;
esac
# A search that matches nothing must come back empty, not fall back to all of
# them — a picker that ignores the query is worse than no picker.
R=$(api GET '/diagnoses/common?search=zzzznotathing' '' "${AUTH[@]}")
case "$(body_of "$R")" in
  *'"diagnoses":[]'*) pass "an unmatched search returns nothing" ;;
  *)                  fail "the search filter is not being applied" ;;
esac

R=$(api POST "/encounters/$ENC/procedures" '{"name":"Pulpotomy","site":"#26","outcome":"Uneventful"}' "${AUTH[@]}")
expect "record procedure" "$(status_of "$R")" "201"

# A procedure picked from the catalogue carries the service with it, so the
# choice that records it is the choice that prices it (sec 27).
PROCSVC=$(printf '%s' "$(body_of "$(api GET '/services?category=procedure' '' "${OAUTH[@]}")")" \
  | grep -o '"id":[0-9]*' | head -1 | sed 's/.*://')
if [ -n "$PROCSVC" ]; then
  R=$(api POST "/encounters/$ENC/procedures" \
    "{\"name\":\"From the catalogue\",\"service_id\":$PROCSVC}" "${AUTH[@]}")
  expect "a procedure can name a catalogue service" "$(status_of "$R")" "201"
  case "$(body_of "$R")" in
    *"\"service_id\":$PROCSVC"*) pass "and the link is stored, not dropped" ;;
    *)                           fail "service_id did not survive the write" ;;
  esac
else
  fail "no priced procedure in the catalogue to link to"
fi

R=$(api POST "/encounters/$ENC/lab-orders" '{"priority":"routine","clinical_notes":"Pre-op bloods","tests":[{"name":"CBC","price":800},{"name":"PT/INR"}]}' "${AUTH[@]}")
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

# The same catalogue medicine under its brand name. The check must read what
# the medicine IS, not the label the client happened to compose: this line
# carries medication_id, and the catalogue knows that id is Amoxicillin.
# The suite makes its own subject rather than hoping the seeded allergies and
# the seeded catalogue happen to line up: take any branded medicine and record
# an allergy to its generic name.
BRANDED=$("$MYSQL" -u root "$DB" -N -e "SELECT id FROM medications
   WHERE organization_id = 1 AND brand_name IS NOT NULL AND brand_name <> ''
   ORDER BY id LIMIT 1" 2>/dev/null)
if [ -n "$BRANDED" ]; then
  GENERIC=$("$MYSQL" -u root "$DB" -N -e "SELECT name FROM medications WHERE id=$BRANDED")
  BRAND=$("$MYSQL"   -u root "$DB" -N -e "SELECT brand_name FROM medications WHERE id=$BRANDED")

  api POST "/patients/$PATIENT/allergies" \
    "{\"substance\":\"$GENERIC\",\"reaction\":\"Hives\",\"severity\":\"moderate\"}" \
    "${AUTH[@]}" > /dev/null

  R=$(api PUT "/prescriptions/$RX" \
    "{\"items\":[{\"medication_id\":$BRANDED,\"medication_name\":\"$BRAND\",\"dosage\":\"1 tablet\"}]}" \
    "${AUTH[@]}")
  case "$(body_of "$R")" in
    *ALLERGY*) pass "caught under the brand name too ($BRAND -> $GENERIC)" ;;
    *)         fail "the allergy check depends on how the client wrote the name" ;;
  esac
else
  fail "no branded medicine in the catalogue to test with"
fi

# And it must not cry wolf: an unrelated medicine warns about nothing.
R=$(api PUT "/prescriptions/$RX" \
  '{"items":[{"medication_name":"Paracetamol 500mg","dosage":"1 tablet"}]}' "${AUTH[@]}")
case "$(body_of "$R")" in
  *ALLERGY*) fail "an unrelated medicine raised an allergy warning" ;;
  *)         pass "an unrelated medicine raises nothing" ;;
esac

# Put the allergen back, so the issued prescription is the one the rest of
# this section expects to print.
R=$(api PUT "/prescriptions/$RX" '{"items":[{"medication_name":"Penicillin V","dosage":"250mg","frequency":"four times a day","duration":"7 days"}]}' "${AUTH[@]}")

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
echo "[8a] The day's tiles count the day's list (sec 4)"

# `counts` is a SQL aggregate; `today` is the calendar query. They are two
# paths to the same day, and the dashboard shows them side by side — the tile
# says "3 completed" and pressing it lists those three. If the two ever
# disagree the screen is lying, whichever half is right.
R=$(api GET /doctors/dashboard '' "${AUTH[@]}")
B=$(body_of "$R")
COUNT_DONE=$(printf '%s' "$B" | grep -o '"completed":[0-9]*' | head -1 | sed 's/.*://')
COUNT_CANX=$(printf '%s' "$B" | grep -o '"cancelled":[0-9]*' | head -1 | sed 's/.*://')

# Rows in `today`, which starts after the "today" key.
TODAY_BLOCK=$(printf '%s' "$B" | sed 's/.*"today":\[//; s/\],"open_encounter".*//')
ROWS_DONE=$(printf '%s' "$TODAY_BLOCK" | grep -o '"status":"completed"' | wc -l | tr -d ' ')
ROWS_CANX=$(printf '%s' "$TODAY_BLOCK" | grep -o '"status":"cancelled"\|"status":"no_show"' | wc -l | tr -d ' ')

expect "completed tile matches the completed rows" "$COUNT_DONE" "$ROWS_DONE"
# The tile counts no-shows with the cancellations, because an hour nobody
# turned up for cost the doctor the same as one that was called off.
expect "cancelled tile counts no-shows too"        "$COUNT_CANX" "$ROWS_CANX"

# ---------------------------------------------------------------
echo
echo "[8b] The doctor's money tiles agree with each other (sec 4)"

# A draft is not billed: it has no invoice number, the patient has never seen
# it, and it may never be issued. All three figures skip drafts, and they have
# to skip the same ones — counting one as revenue while leaving its balance
# out of `outstanding` showed money billed, less collected, and nothing owed.
R=$(api GET /doctors/dashboard '' "${AUTH[@]}")
B=$(body_of "$R")
BILLED_BEFORE=$(jval "$B" billed_today)
# The dashboard's own doctor, which is the signed-in one and not necessarily
# $DOCTOR — the invoice has to hang off a visit THIS dashboard counts.
DASHDOC=$(jnum "$B" id)

# One of that doctor's own visits that has NOT been invoiced yet — an
# encounter carries at most one invoice, so re-using the last run's subject
# would fail with a 409 on the second run and say nothing about the code.
DENC=$("$MYSQL" -u root "$DB" -N -e "SELECT e.id FROM encounters e
     LEFT JOIN invoices i ON i.encounter_id = e.id
    WHERE e.organization_id = 1 AND e.doctor_id = $DASHDOC AND i.id IS NULL
    ORDER BY e.id DESC LIMIT 1" 2>/dev/null)
DPAT=$("$MYSQL" -u root "$DB" -N -e "SELECT patient_id FROM encounters
   WHERE id = ${DENC:-0}" 2>/dev/null)

# Every run of this section bills one of this doctor's visits, so the supply
# is finite — and it ran out. What that looked like was not a failing tile but
# a missing fixture: the section reported "no visit" and tested nothing, which
# is the quietest way for a check to stop checking. So it makes its own when
# the well is dry. The patient has to be one with no open consultation, since
# a second open visit for the same person is refused by design.
if [ -z "$DENC" ]; then
  FRESHPAT=$("$MYSQL" -u root "$DB" -N -e "SELECT p.id FROM patients p
       WHERE p.organization_id = 1
         AND NOT EXISTS (SELECT 1 FROM encounters e
                          WHERE e.patient_id = p.id AND e.status = 'open')
       ORDER BY p.id DESC LIMIT 1" 2>/dev/null | tr -d '')
  if [ -n "$FRESHPAT" ]; then
    R=$(api POST /encounters "{\"patient_id\":$FRESHPAT,\"doctor_id\":$DASHDOC}" "${AUTH[@]}")
    DENC=$(jnum "$(body_of "$R")" id)
    DPAT=$FRESHPAT
  fi
fi

# The first service the catalogue has a price for; a draft needs a priced line.
PRICED=$(printf '%s' "$(body_of "$(api GET /services '' "${OAUTH[@]}")")" \
  | grep -o '"id":[0-9]*,[^}]*"price":"[1-9][0-9.]*"' | head -1 | grep -o '^"id":[0-9]*' | sed 's/.*://')

if [ -n "$PRICED" ] && [ -n "$DENC" ] && [ -n "$DPAT" ]; then
  R=$(api POST /invoices \
    "{\"patient_id\":$DPAT,\"encounter_id\":$DENC,\"items\":[{\"service_id\":$PRICED,\"quantity\":1}]}" \
    "${OAUTH[@]}")
  expect "raise a draft invoice on this doctor's visit" "$(status_of "$R")" "201"
  DRAFTINV=$(jnum "$(body_of "$R")" id)
  expect "and it really is a draft" "$(jval "$(body_of "$R")" status)" "draft"

  R=$(api GET /doctors/dashboard '' "${AUTH[@]}")
  expect "a draft does not count as billed" \
    "$(jval "$(body_of "$R")" billed_today)" "$BILLED_BEFORE"

  # Issuing it must move the figure the draft did not, or the filter above is
  # not excluding drafts — it is excluding everything.
  api POST "/invoices/$DRAFTINV/issue" '' "${OAUTH[@]}" > /dev/null
  R=$(api GET /doctors/dashboard '' "${AUTH[@]}")
  BILLED_AFTER=$(jval "$(body_of "$R")" billed_today)
  [ "$BILLED_AFTER" != "$BILLED_BEFORE" ] && pass "issuing it does ($BILLED_BEFORE -> $BILLED_AFTER)" \
                                          || fail "billed_today ignored an issued invoice too"
else
  fail "no priced service or no visit for the dashboard's doctor"
fi

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
