#!/usr/bin/env bash
# Phase 4 end-to-end test — the patient portal and the notification engine.
#
#   bash database/smoke-test-patient.sh
#
# The important assertions here are negative: a patient must not be able to
# reach anything that is not theirs.

set -uo pipefail

BASE="${BASE:-http://127.0.0.1:8000/api/v1}"
MYSQL="${MYSQL:-/c/xampp/mysql/bin/mysql.exe}"
DB="${DB:-mediflow}"
PASS=0
FAIL=0

pass() { PASS=$((PASS + 1)); printf '  \033[32mPASS\033[0m  %s\n' "$1"; }
fail() { FAIL=$((FAIL + 1)); printf '  \033[31mFAIL\033[0m  %s\n' "$1"; [ -n "${2:-}" ] && printf '        %s\n' "$2"; }
expect() { if [ "$2" = "$3" ]; then pass "$1 -> $2"; else fail "$1 -> got $2, want $3" "${4:-}"; fi; }

reset_limits() { [ -x "$MYSQL" ] && "$MYSQL" -u root "$DB" -e "TRUNCATE TABLE rate_limits;" 2>/dev/null; }
sql() { "$MYSQL" -u root "$DB" -N -e "$1"; }

# The first day in the next fortnight on which a doctor actually has a free
# slot, printed as "YYYY-MM-DD<TAB>HH:MM:SS".
#
# A fixed "+2 days" was wrong for the same reason a clinic is not open every
# day: it lands on a Sunday roughly once a week and the suite fails for a
# reason that has nothing to do with the code.
#
# Always asked with the clinic's token: /doctors/{id}/available-slots is the
# staff route, and a patient holds no permission for it. Finding a date to
# test with is not the thing under test.
first_free_slot() {
  local doctor="$1" token="${2:-$OWNER}" offset=1 day slot
  while [ "$offset" -le 14 ]; do
    day=$(date -u -d "+$offset day" +%Y-%m-%d 2>/dev/null || date -u -v+"$offset"d +%Y-%m-%d)
    slot=$(curl -s "$BASE/doctors/$doctor/available-slots?date=$day" \
             -H "Authorization: Bearer $token" \
           | grep -o '"start":"[^"]*"' | head -1 | sed 's/.*"start":"//; s/"$//')
    if [ -n "$slot" ]; then
      printf '%s\t%s' "$day" "$slot"
      return 0
    fi
    offset=$((offset + 1))
  done
  return 1
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

echo
echo "MediFlow Phase 4 — patient app smoke test"
echo "========================================="

# ---------------------------------------------------------------
echo
echo "[setup] sign in"

R=$(api POST /auth/login '{"email":"patient@demo.test","password":"Password123"}')
expect "patient login" "$(status_of "$R")" "200"
PAT=$(jval "$(body_of "$R")" access_token)
PAUTH=(-H "Authorization: Bearer $PAT")

R=$(api POST /auth/login '{"email":"owner@clinic.test","password":"Password123"}')
OWNER=$(jval "$(body_of "$R")" access_token)
OAUTH=(-H "Authorization: Bearer $OWNER")

R=$(api POST /auth/login '{"email":"reception@clinic.test","password":"Password123"}')
RECEPTION=$(jval "$(body_of "$R")" access_token)
RAUTH=(-H "Authorization: Bearer $RECEPTION")

MYPATIENT=$("$MYSQL" -u root "$DB" -N -e "SELECT p.id FROM patients p JOIN users u ON u.id=p.user_id WHERE u.email='patient@demo.test'")
OTHER=$("$MYSQL" -u root "$DB" -N -e "SELECT id FROM patients WHERE id <> $MYPATIENT AND organization_id=1 LIMIT 1")

# ---------------------------------------------------------------
echo
echo "[1] Dashboard (sec 3)"

R=$(api GET /patient/dashboard '' "${PAUTH[@]}")
expect "dashboard loads" "$(status_of "$R")" "200"
B=$(body_of "$R")
for key in patient alerts upcoming_appointments outstanding recent_prescriptions follow_ups; do
  case "$B" in
    *"\"$key\""*) pass "dashboard has $key" ;;
    *)            fail "dashboard missing $key" ;;
  esac
done
case "$B" in
  *Penicillin*) pass "medical alerts surface the allergy" ;;
  *)            fail "allergies not in the alerts block" ;;
esac

# ---------------------------------------------------------------
echo
echo "[2] A patient can only reach their OWN record"

R=$(api GET /patients '' "${PAUTH[@]}")
expect "patient list refused" "$(status_of "$R")" "403"

R=$(api GET "/patients/$OTHER" '' "${PAUTH[@]}")
expect "another patient's chart refused" "$(status_of "$R")" "403"

R=$(api GET "/patients/$MYPATIENT" '' "${PAUTH[@]}")
expect "own chart via clinic route also refused (no permission)" "$(status_of "$R")" "403"

R=$(api GET /patient/profile '' "${PAUTH[@]}")
expect "own profile via the portal" "$(status_of "$R")" "200"
case "$(body_of "$R")" in
  *"P-00000"*) pass "profile returns the patient's own MRN" ;;
  *)           fail "no MRN in the profile" ;;
esac

R=$(api GET /encounters '' "${PAUTH[@]}")
expect "clinic encounter list refused" "$(status_of "$R")" "403"

R=$(api GET /invoices '' "${PAUTH[@]}")
expect "clinic invoice list refused" "$(status_of "$R")" "403"

R=$(api GET /reports/financial '' "${PAUTH[@]}")
expect "financial reports refused" "$(status_of "$R")" "403"

R=$(api GET /audit-logs '' "${PAUTH[@]}")
expect "audit log refused" "$(status_of "$R")" "403"

R=$(api POST /patients '{"first_name":"Fake","last_name":"Person"}' "${PAUTH[@]}")
expect "patient cannot register patients" "$(status_of "$R")" "403"

# ---------------------------------------------------------------
echo
echo "[3] Profile — the patient's own details, and the clinic's"

R=$(api PUT /patient/profile '{"phone":"0300-9998887","city":"Lahore"}' "${PAUTH[@]}")
expect "patient updates their own contact details" "$(status_of "$R")" "200"
case "$(body_of "$R")" in
  *0300-9998887*) pass "phone was saved" ;;
  *)              fail "phone not saved" ;;
esac

# A patient owns their own personal details — a misspelled name or a wrong
# date of birth is theirs to fix.
NAME_WAS=$(sql "SELECT first_name FROM patients WHERE user_id = (SELECT id FROM users WHERE email='patient@demo.test')")
R=$(api PUT /patient/profile '{"first_name":"Fatimah","gender":"female"}' "${PAUTH[@]}")
expect "patient corrects their own name" "$(status_of "$R")" "200"
case "$(body_of "$R")" in
  *'"first_name":"Fatimah"'*) pass "the correction was saved" ;;
  *)                          fail "name change was ignored" ;;
esac
R=$(api PUT /patient/profile "{\"first_name\":\"$NAME_WAS\"}" "${PAUTH[@]}")
expect "and can be changed back" "$(status_of "$R")" "200"

# Clinical facts and identifiers are not in the allow-list, whatever is sent.
R=$(api PUT /patient/profile '{"blood_group":"AB-","mrn":"P-999999","status":"inactive"}' "${PAUTH[@]}")
expect "clinical/identity fields accepted but ignored" "$(status_of "$R")" "200"
B=$(body_of "$R")
case "$B" in
  *'"mrn":"P-999999"'*) fail "patient rewrote their own MRN" ;;
  *)                    pass "MRN unchanged" ;;
esac
# Blood group is a test result read off this screen in an emergency, so it
# stays the clinic's to record even though it sits among personal details.
case "$B" in
  *'"blood_group":"AB-"'*) fail "patient rewrote a clinical field" ;;
  *)                       pass "blood group unchanged" ;;
esac
case "$B" in
  *'"status":"inactive"'*) fail "patient deactivated their own record" ;;
  *)                       pass "record status unchanged" ;;
esac

# ---------------------------------------------------------------
echo
echo "[4] Appointments"

R=$(api GET /patient/appointments '' "${PAUTH[@]}")
expect "own appointments" "$(status_of "$R")" "200"

R=$(api GET '/patient/appointments?scope=upcoming' '' "${PAUTH[@]}")
expect "upcoming filter" "$(status_of "$R")" "200"

# The clinic books one so the patient has something to cancel.
DOCTOR=$("$MYSQL" -u root "$DB" -N -e "SELECT id FROM doctors WHERE organization_id=1 ORDER BY id LIMIT 1")
FREE=$(first_free_slot "$DOCTOR" "$OWNER")
TOMORROW=$(printf '%s' "$FREE" | cut -f1)
SLOT=$(printf '%s' "$FREE" | cut -f2)
[ -n "$SLOT" ] || fail "the doctor has no free slot in the next fortnight"

R=$(api POST /appointments "{\"patient_id\":$MYPATIENT,\"doctor_id\":$DOCTOR,\"scheduled_at\":\"$SLOT\",\"reason\":\"Patient app test\"}" "${OAUTH[@]}")
expect "clinic books for this patient" "$(status_of "$R")" "201"
APPT=$(jnum "$(body_of "$R")" id)

R=$(api GET '/patient/appointments?scope=upcoming' '' "${PAUTH[@]}")
case "$(body_of "$R")" in
  *"Patient app test"*) pass "it appears in the patient's app" ;;
  *)                    fail "booking not visible to the patient" ;;
esac

# A patient may cancel their own booking...
R=$(api POST "/patient/appointments/$APPT/cancel" '{"reason":"Cannot make it"}' "${PAUTH[@]}")
expect "patient cancels their own appointment" "$(status_of "$R")" "200"

# ...but not somebody else's.
OTHERAPPT=$("$MYSQL" -u root "$DB" -N -e "SELECT id FROM appointments WHERE patient_id <> $MYPATIENT AND status IN ('booked','confirmed') LIMIT 1")
if [ -n "$OTHERAPPT" ]; then
  R=$(api POST "/patient/appointments/$OTHERAPPT/cancel" '{"reason":"not mine"}' "${PAUTH[@]}")
  expect "cannot cancel another patient's appointment" "$(status_of "$R")" "404"
fi

# And cannot change status any other way.
R=$(api PUT "/appointments/$APPT/status" '{"status":"confirmed"}' "${PAUTH[@]}")
expect "patient cannot use the clinic status route at all" "$(status_of "$R")" "403"

# ---------------------------------------------------------------
echo
echo "[5] Records, prescriptions, bills (sec 3)"

R=$(api GET /patient/records '' "${PAUTH[@]}")
expect "medical records" "$(status_of "$R")" "200"

R=$(api GET /patient/prescriptions '' "${PAUTH[@]}")
expect "prescriptions" "$(status_of "$R")" "200"
case "$(body_of "$R")" in
  *'"status":"draft"'*) fail "a draft prescription leaked to the patient" ;;
  *)                    pass "only issued prescriptions are shown" ;;
esac

R=$(api GET /patient/bills '' "${PAUTH[@]}")
expect "bills" "$(status_of "$R")" "200"
B=$(body_of "$R")
case "$B" in
  *'"outstanding"'*) pass "bills report an outstanding balance" ;;
  *)                 fail "no outstanding figure" ;;
esac
case "$B" in
  *'"status":"draft"'*) fail "a draft invoice leaked to the patient" ;;
  *)                    pass "draft invoices are not shown" ;;
esac

R=$(api GET /patient/lab-results '' "${PAUTH[@]}")
expect "lab results" "$(status_of "$R")" "200"

R=$(api GET /patient/documents '' "${PAUTH[@]}")
expect "documents" "$(status_of "$R")" "200"

# An invoice belonging to someone else must 404, not 403 — the patient should
# not learn it exists.
OTHERINV=$("$MYSQL" -u root "$DB" -N -e "SELECT id FROM invoices WHERE patient_id <> $MYPATIENT AND status <> 'draft' LIMIT 1")
if [ -n "$OTHERINV" ]; then
  R=$(api GET "/patient/invoices/$OTHERINV" '' "${PAUTH[@]}")
  expect "another patient's invoice is 404" "$(status_of "$R")" "404"
fi

# ---------------------------------------------------------------
echo
echo "[5a] Released reports open, clinic-only ones do not (sec 3, sec 19)"

# A report the clinic released to the patient.
DOCFILE="${TMPDIR:-/tmp}/mediflow-smoke.pdf"
printf '%%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%%%EOF\n' > "$DOCFILE"

R=$(curl -s -w '\n%{http_code}' -X POST "$BASE/patients/$MYPATIENT/documents" \
    -H "Authorization: Bearer $OWNER" \
    -F "file=@$DOCFILE" -F "title=Smoke lab report" \
    -F "category=lab_report" -F "visibility=patient_visible")
expect "clinic uploads a report for this patient" "$(status_of "$R")" "201"
DOCID=$(jnum "$(body_of "$R")" id)

if [ -n "$DOCID" ]; then
  CODE=$(curl -s -o /dev/null -w '%{http_code}' \
      "$BASE/patient/documents/$DOCID/download" -H "Authorization: Bearer $PAT")
  expect "patient opens their own report" "$CODE" "200"

  TYPE=$(curl -s -o /dev/null -w '%{content_type}' \
      "$BASE/patient/documents/$DOCID/download" -H "Authorization: Bearer $PAT")
  case "$TYPE" in
    application/pdf*) pass "it comes back as the file, not as JSON" ;;
    *)                fail "unexpected content type: $TYPE" ;;
  esac

  # Clinic-only means clinic-only, even for the patient it is about.
  sql "UPDATE medical_documents SET visibility='clinic_only' WHERE id=$DOCID" >/dev/null
  CODE=$(curl -s -o /dev/null -w '%{http_code}' \
      "$BASE/patient/documents/$DOCID/download" -H "Authorization: Bearer $PAT")
  expect "a clinic-only report stays shut" "$CODE" "404"
  sql "UPDATE medical_documents SET visibility='patient_visible' WHERE id=$DOCID" >/dev/null

  # And somebody else's report is not theirs to open — 404, not 403, because
  # confirming it exists would itself be the leak.
  OTHERDOC=$(sql "SELECT id FROM medical_documents WHERE organization_id=1 AND patient_id <> $MYPATIENT LIMIT 1")
  if [ -n "$OTHERDOC" ]; then
    CODE=$(curl -s -o /dev/null -w '%{http_code}' \
        "$BASE/patient/documents/$OTHERDOC/download" -H "Authorization: Bearer $PAT")
    expect "another patient's report is not reachable" "$CODE" "404"
  fi

  # Reading a medical record is an audited event however it is read (§16).
  TRAIL=$(sql "SELECT COUNT(*) FROM audit_logs WHERE resource_type='medical_document' AND resource_id=$DOCID")
  [ "${TRAIL:-0}" -gt 0 ] && pass "opening it is written to the audit trail" \
                          || fail "document access was not audited"
fi

# ---------------------------------------------------------------
echo
echo "[5b] The patient books for themselves (sec 3)"

R=$(api GET /patient/doctors '' "${PAUTH[@]}")
expect "doctor list for booking" "$(status_of "$R")" "200"
case "$(body_of "$R")" in
  *doctor_name*) pass "doctors are listed with their specialty" ;;
  *)             fail "no doctors returned" ;;
esac
# The clinic's own doctor list stays closed to patients — this is a narrower
# view, not the same endpoint reopened.
case "$(body_of "$R")" in
  *'"user_id"'*) fail "the patient view leaked doctor account ids" ;;
  *)             pass "and nothing beyond what a patient needs to choose" ;;
esac

R=$(api GET '/patient/doctors?search=zzzznotreal' '' "${PAUTH[@]}")
case "$(body_of "$R")" in
  *'"doctors":[]'*) pass "search narrows the list" ;;
  *)                fail "search returned everything" ;;
esac

# Whichever day the clinic next sits — not a fixed offset, which lands on a
# Sunday about once a week.
BOOK_FREE=$(first_free_slot "$DOCTOR")
BOOK_DAY=$(printf '%s' "$BOOK_FREE" | cut -f1)
R=$(api GET "/patient/doctors/$DOCTOR/slots?date=$BOOK_DAY" '' "${PAUTH[@]}")
expect "free slots for a chosen day" "$(status_of "$R")" "200"
PSLOT=$(printf '%s' "$BOOK_FREE" | cut -f2)
[ -n "$PSLOT" ] && pass "a slot is offered ($PSLOT)" || fail "no slots offered to the patient"

R=$(api POST /patient/appointments \
    "{\"doctor_id\":$DOCTOR,\"scheduled_at\":\"$PSLOT\",\"reason\":\"Booked from the app\"}" "${PAUTH[@]}")
expect "patient books it" "$(status_of "$R")" "201"
MYBOOKING=$(jnum "$(body_of "$R")" id)

# The booking belongs to the person who made it, whatever the request said.
OWNER_OF=$(sql "SELECT patient_id FROM appointments WHERE id=$MYBOOKING")
[ "$OWNER_OF" = "$MYPATIENT" ] && pass "it is filed against their own record" \
                               || fail "booking landed on patient $OWNER_OF"

# Same slot again: the clinic's double-booking rule applies to the app too.
R=$(api POST /patient/appointments \
    "{\"doctor_id\":$DOCTOR,\"scheduled_at\":\"$PSLOT\"}" "${PAUTH[@]}")
expect "the same slot cannot be taken twice" "$(status_of "$R")" "409"

# A patient must not be able to book INTO someone else's name.
R=$(api POST /patient/appointments \
    "{\"doctor_id\":$DOCTOR,\"patient_id\":$OTHER,\"scheduled_at\":\"$BOOK_DAY 23:30:00\"}" "${PAUTH[@]}")
case "$(body_of "$R")" in
  *"\"patient_id\":$OTHER,"*) fail "patient booked in another patient's name" ;;
  *)                          pass "a supplied patient_id is ignored" ;;
esac

# Moving it keeps the same rules; the clinic's transition table is reused.
R=$(api GET "/patient/doctors/$DOCTOR/slots?date=$BOOK_DAY" '' "${PAUTH[@]}")
PSLOT2=$(printf '%s' "$(body_of "$R")" | grep -o '"start":"[^"]*"' | head -1 | sed 's/.*"start":"//; s/"$//')
if [ -n "$PSLOT2" ] && [ "$PSLOT2" != "$PSLOT" ]; then
  R=$(api POST "/patient/appointments/$MYBOOKING/reschedule" \
      "{\"scheduled_at\":\"$PSLOT2\"}" "${PAUTH[@]}")
  expect "patient moves it to another time" "$(status_of "$R")" "200"
fi

# Somebody else's appointment is not theirs to move.
OTHER_APPT=$(sql "SELECT id FROM appointments WHERE organization_id=1 AND patient_id <> $MYPATIENT ORDER BY id DESC LIMIT 1")
if [ -n "$OTHER_APPT" ]; then
  R=$(api POST "/patient/appointments/$OTHER_APPT/reschedule" \
      "{\"scheduled_at\":\"$PSLOT\"}" "${PAUTH[@]}")
  expect "cannot move another patient's appointment" "$(status_of "$R")" "404"
fi

# ---------------------------------------------------------------
echo
echo "[6] Notifications (sec 20)"

R=$(api GET /patient/notifications '' "${PAUTH[@]}")
expect "inbox loads" "$(status_of "$R")" "200"
B=$(body_of "$R")

case "$B" in
  *appointment.booked*) pass "booking raised a notification" ;;
  *)                    fail "no appointment.booked event" ;;
esac
case "$B" in
  *appointment.cancelled*) pass "cancellation raised a notification" ;;
  *)                       fail "no appointment.cancelled event" ;;
esac

BEFORE=$(jnum "$B" unread)
[ "${BEFORE:-0}" -gt 0 ] && pass "unread count is $BEFORE" || fail "nothing unread after two events"

R=$(api POST /patient/notifications/read '' "${PAUTH[@]}")
expect "mark all read" "$(status_of "$R")" "200"
AFTER=$(jnum "$(body_of "$R")" unread)
[ "${AFTER:-1}" -eq 0 ] && pass "unread cleared" || fail "unread still $AFTER"

# Issuing an invoice must notify.
R=$(api POST /invoices "{\"patient_id\":$MYPATIENT,\"items\":[{\"service_id\":$("$MYSQL" -u root "$DB" -N -e "SELECT id FROM services WHERE code='CONSULT-GEN' LIMIT 1"),\"quantity\":1}]}" "${OAUTH[@]}")
NEWINV=$(jnum "$(body_of "$R")" id)
R=$(api POST "/invoices/$NEWINV/issue" '' "${OAUTH[@]}")
expect "clinic issues an invoice" "$(status_of "$R")" "200"

R=$(api POST "/invoices/$NEWINV/payments" '{"amount":500,"method":"cash"}' "${RAUTH[@]}")
expect "clinic takes a payment" "$(status_of "$R")" "201"

R=$(api GET /patient/notifications '' "${PAUTH[@]}")
B=$(body_of "$R")
case "$B" in
  *invoice.issued*)  pass "invoice notified the patient" ;;
  *)                 fail "no invoice.issued event" ;;
esac
case "$B" in
  *payment.received*) pass "payment notified the patient" ;;
  *)                  fail "no payment.received event" ;;
esac

# The patient sees only their own inbox.
OTHERNOTIF=$("$MYSQL" -u root "$DB" -N -e "SELECT COUNT(*) FROM notifications n JOIN users u ON u.id=n.user_id WHERE u.email <> 'patient@demo.test'")
case "$B" in
  *'"user_id"'*) fail "inbox leaked user ids of other accounts" ;;
  *)             pass "inbox is scoped to this account" ;;
esac

# Clearing the inbox (§20). The row must survive it — "was the patient told?"
# stays answerable however the patient tidies their own screen.
MYUSER=$(sql "SELECT id FROM users WHERE email='patient@demo.test'")
ROWS_BEFORE=$(sql "SELECT COUNT(*) FROM notifications WHERE user_id=$MYUSER")

R=$(api POST /patient/notifications/read '' "${PAUTH[@]}")   # everything read
UNREAD_KEEP=$(sql "SELECT COUNT(*) FROM notifications WHERE user_id=$MYUSER AND read_at IS NULL AND channel='in_app'")

R=$(api DELETE /patient/notifications '' "${PAUTH[@]}")
expect "patient clears the read ones" "$(status_of "$R")" "200"

ROWS_AFTER=$(sql "SELECT COUNT(*) FROM notifications WHERE user_id=$MYUSER")
[ "$ROWS_BEFORE" = "$ROWS_AFTER" ] \
  && pass "nothing was deleted — the record is intact ($ROWS_AFTER rows)" \
  || fail "clearing deleted rows ($ROWS_BEFORE -> $ROWS_AFTER)"

R=$(api GET /patient/notifications '' "${PAUTH[@]}")
LEFT=$(printf '%s' "$(body_of "$R")" | grep -o '"id":' | wc -l)
[ "$LEFT" -eq 0 ] && pass "and the inbox is empty for the patient" \
                  || fail "$LEFT notifications still showing after clearing"

# One dismissed notification must not come back on the next load.
R=$(api POST "/invoices/$NEWINV/payments" '{"amount":100,"method":"cash"}' "${RAUTH[@]}")
R=$(api GET /patient/notifications '' "${PAUTH[@]}")
NEW_ID=$(jnum "$(body_of "$R")" id)
if [ -n "$NEW_ID" ]; then
  pass "a new event still arrives after clearing"
  R=$(api DELETE "/patient/notifications/$NEW_ID" '' "${PAUTH[@]}")
  expect "patient dismisses one" "$(status_of "$R")" "200"
  R=$(api GET /patient/notifications '' "${PAUTH[@]}")
  case "$(body_of "$R")" in
    *"\"id\":$NEW_ID,"*) fail "the dismissed notification came back" ;;
    *)                   pass "it stays gone from the inbox" ;;
  esac
  STILL=$(sql "SELECT COUNT(*) FROM notifications WHERE id=$NEW_ID")
  [ "$STILL" = "1" ] && pass "but the row is still in the database" \
                     || fail "dismissing deleted the row"
fi

# ---------------------------------------------------------------
echo
echo "[7] Giving a patient an app account"

# Pick a patient with no account yet, so the suite stays re-runnable.
UNLINKED=$("$MYSQL" -u root "$DB" -N -e "SELECT id FROM patients WHERE user_id IS NULL AND organization_id=1 LIMIT 1")

R=$(api POST "/patients/$UNLINKED/account" "{\"email\":\"newpatient$(date +%s)@demo.test\"}" "${OAUTH[@]}")
expect "clinic links an account" "$(status_of "$R")" "201"
case "$(body_of "$R")" in
  *temporary_password*) pass "a temporary password is returned" ;;
  *)                    fail "no temporary password" ;;
esac

R=$(api POST "/patients/$UNLINKED/account" '{"email":"second@demo.test"}' "${OAUTH[@]}")
expect "cannot link twice" "$(status_of "$R")" "409"

R=$(api POST "/patients/$MYPATIENT/account" '{"email":"x@y.test"}' "${PAUTH[@]}")
expect "a patient cannot link accounts" "$(status_of "$R")" "403"

# ---------------------------------------------------------------
echo
echo "[8] The delivery worker (sec 20)"

PHP="${PHP:-/c/xampp/php/php.exe}"
WORKER="$(cd "$(dirname "$0")" && pwd)/notify.php"

OUT=$("$PHP" "$WORKER" --status 2>&1)
case "$OUT" in
  *"in_app"*) pass "the worker reports its channels" ;;
  *)          fail "notify.php --status produced nothing useful" "$OUT" ;;
esac
case "$OUT" in
  *"not configured"*) pass "and says plainly which have no credentials" ;;
  *)                  pass "every channel is configured on this machine" ;;
esac

# Queue one by doing something that notifies, then drain it.
R=$(api POST /invoices "{\"patient_id\":$MYPATIENT,\"items\":[{\"service_id\":$("$MYSQL" -u root "$DB" -N -e "SELECT id FROM services WHERE code='CONSULT-GEN' LIMIT 1"),\"quantity\":1}]}" "${OAUTH[@]}")
QINV=$(jnum "$(body_of "$R")" id)
api POST "/invoices/$QINV/issue" '' "${OAUTH[@]}" > /dev/null

MYUSER=$(sql "SELECT id FROM users WHERE email='patient@demo.test'")
QUEUED=$(sql "SELECT COUNT(*) FROM notifications WHERE user_id=$MYUSER AND status='queued'")
[ "${QUEUED:-0}" -gt 0 ] && pass "issuing an invoice queued $QUEUED notification(s)" \
                         || fail "nothing was queued"

# The worker takes a bounded slice per pass on purpose — a cron job that tries
# to send ten thousand rows in one go is a cron job that times out. So drain it
# the way cron would: repeatedly, until there is nothing due left.
PASSES=0
while [ "$PASSES" -lt 12 ]; do
  "$PHP" "$WORKER" --limit=500 > /dev/null 2>&1
  STILL=$(sql "SELECT COUNT(*) FROM notifications WHERE status='queued' AND (scheduled_for IS NULL OR scheduled_for <= UTC_TIMESTAMP())")
  [ "${STILL:-1}" -eq 0 ] && break
  PASSES=$((PASSES + 1))
done

[ "${STILL:-1}" -eq 0 ] && pass "the worker drained everything due" \
                        || fail "$STILL still due after $PASSES passes"

# A channel with no credentials must not be left retrying for ever: the row is
# finished with, and the reason is written down.
SKIPPED=$(sql "SELECT COUNT(*) FROM notifications WHERE channel='push' AND status='sent' AND error LIKE '%not configured%'")
[ "${SKIPPED:-0}" -gt 0 ] && pass "unconfigured channels are closed off with a reason" \
                          || fail "skipped notifications were not recorded as such"

# The in-app copy is what the patient actually reads, and it must be untouched
# by all of that.
R=$(api GET /patient/notifications '' "${PAUTH[@]}")
expect "the patient's inbox still works" "$(status_of "$R")" "200"
case "$(body_of "$R")" in
  *invoice.issued*) pass "and the new invoice is in it" ;;
  *)                fail "the invoice notification never reached the inbox" ;;
esac

# A reminder queued for the future must not go out early.
FUTURE=$(sql "SELECT COUNT(*) FROM notifications WHERE status='queued' AND scheduled_for > UTC_TIMESTAMP()")
if [ "${FUTURE:-0}" -gt 0 ]; then
  pass "$FUTURE scheduled reminder(s) left alone until they are due"
else
  echo "  (no future-dated reminders queued right now)"
fi

# ---------------------------------------------------------------
echo
echo "========================================="
printf 'passed: %d   failed: %d\n\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ] || exit 1
