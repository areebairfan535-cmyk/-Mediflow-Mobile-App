#!/usr/bin/env bash
# §20: the two notifications that were defined but never sent.
set -uo pipefail
BASE="${BASE:-http://127.0.0.1:8000/api/v1}"
MYSQL="${MYSQL:-/c/xampp/mysql/bin/mysql.exe}"
DB="${DB:-mediflow}"
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

OWNER=$(tok owner@clinic.test)
PAT=$(tok patient@demo.test)
[ -z "$OWNER" ] || [ -z "$PAT" ] && { echo "could not sign in"; exit 1; }
O=(-H "Authorization: Bearer $OWNER" -H 'X-Organization-Id: 1' -H 'Content-Type: application/json')
P=(-H "Authorization: Bearer $PAT" -H 'Content-Type: application/json')

PID=$(sql "SELECT p.id FROM patients p JOIN users u ON u.id=p.user_id WHERE u.email='patient@demo.test'")
PUSER=$(sql "SELECT id FROM users WHERE email='patient@demo.test'")

# ---------------------------------------------------------------
echo
echo "[1] An invoice going overdue tells the patient"

INV=$(sql "SELECT id FROM invoices WHERE patient_id=$PID AND status IN ('issued','partially_paid') ORDER BY id LIMIT 1")
if [ -z "$INV" ]; then
  echo "  no issued invoice — run database/seed_billing.php"; exit 1
fi
sql "UPDATE invoices SET due_date = DATE_SUB(CURDATE(), INTERVAL 3 DAY) WHERE id=$INV"
BEFORE=$(sql "SELECT COUNT(*) FROM notifications WHERE user_id=$PUSER AND event='invoice.overdue'")

R=$(curl -s -X POST "${O[@]}" "$BASE/invoices/mark-overdue")
echo "$R" | grep -q '"updated"' && ok "mark-overdue ran" || bad "mark-overdue failed" "$R"

STATUS=$(sql "SELECT status FROM invoices WHERE id=$INV")
want "the invoice is overdue" "$STATUS" "overdue"

AFTER=$(sql "SELECT COUNT(*) FROM notifications WHERE user_id=$PUSER AND event='invoice.overdue'")
if [ "$AFTER" -gt "$BEFORE" ]; then ok "a notification was queued ($BEFORE -> $AFTER)"
else bad "no overdue notification" "$BEFORE -> $AFTER"; fi

BODY=$(sql "SELECT body FROM notifications WHERE user_id=$PUSER AND event='invoice.overdue' ORDER BY id DESC LIMIT 1")
case "$BODY" in *"past its due date"*) ok "the message reads properly: $BODY" ;;
                *) bad "unexpected body" "$BODY" ;; esac

echo "  (it reaches the patient's own inbox)"
INBOX=$(curl -s "${P[@]}" "$BASE/patient/notifications")
echo "$INBOX" | grep -q 'invoice.overdue' && ok "it is in the patient's inbox" || bad "not in the inbox"

echo
echo "  running it twice does not notify twice"
B2=$(sql "SELECT COUNT(*) FROM notifications WHERE user_id=$PUSER AND event='invoice.overdue'")
curl -s -o /dev/null -X POST "${O[@]}" "$BASE/invoices/mark-overdue"
A2=$(sql "SELECT COUNT(*) FROM notifications WHERE user_id=$PUSER AND event='invoice.overdue'")
want "already-overdue invoices are left alone" "$A2" "$B2"

# ---------------------------------------------------------------
echo
echo "[2] Lab results landing tells the patient"

ORDER=$(sql "SELECT id FROM lab_orders WHERE patient_id=$PID AND status <> 'completed' ORDER BY id LIMIT 1")
if [ -z "$ORDER" ]; then
  # A lab order hangs off a consultation, and a completed one is closed to
  # edits — so use an open encounter, opening one if there is none.
  ENC=$(sql "SELECT id FROM encounters WHERE patient_id=$PID AND status='open' ORDER BY id DESC LIMIT 1")
  if [ -z "$ENC" ]; then
    # A doctor may hold only one open consultation at a time, so pick one who
    # is free rather than assuming the first is.
    DOC=$(sql "SELECT d.id FROM doctors d
                WHERE d.organization_id=1
                  AND d.id NOT IN (SELECT doctor_id FROM encounters WHERE status='open')
                ORDER BY d.id LIMIT 1")
    if [ -z "$DOC" ]; then
      # Everyone is busy — close the oldest open consultation so the test can
      # open its own.
      OLD=$(sql "SELECT id FROM encounters WHERE organization_id=1 AND status='open' ORDER BY id LIMIT 1")
      sql "UPDATE encounters SET status='completed', completed_at=NOW() WHERE id=$OLD"
      DOC=$(sql "SELECT id FROM doctors WHERE organization_id=1 ORDER BY id LIMIT 1")
    fi
    curl -s -o /dev/null -X POST "${O[@]}" "$BASE/encounters" \
      -d "{\"patient_id\":$PID,\"doctor_id\":$DOC,\"type\":\"outpatient\",\"chief_complaint\":\"Notification test\"}"
    ENC=$(sql "SELECT id FROM encounters WHERE patient_id=$PID AND status='open' ORDER BY id DESC LIMIT 1")
  fi
  curl -s -o /dev/null -X POST "${O[@]}" "$BASE/encounters/$ENC/lab-orders" \
    -d '{"priority":"routine","clinical_notes":"Routine check"}'
  ORDER=$(sql "SELECT id FROM lab_orders WHERE patient_id=$PID AND status <> 'completed' ORDER BY id DESC LIMIT 1")
fi
[ -z "$ORDER" ] && { echo "  could not get a lab order to work with"; exit 1; }
echo "  (lab order $ORDER)"

BEFORE=$(sql "SELECT COUNT(*) FROM notifications WHERE user_id=$PUSER AND event='lab.result_ready'")
R=$(curl -s -X POST "${O[@]}" "$BASE/lab-orders/$ORDER/results" \
      -d '{"results":[{"test_name":"Haemoglobin","value":"13.4","unit":"g/dL","flag":"normal"}]}')
echo "$R" | grep -q '"lab_orders"' && ok "results recorded" || bad "recording failed" "$R"

AFTER=$(sql "SELECT COUNT(*) FROM notifications WHERE user_id=$PUSER AND event='lab.result_ready'")
if [ "$AFTER" -gt "$BEFORE" ]; then ok "a notification was queued ($BEFORE -> $AFTER)"
else bad "no lab notification" "$BEFORE -> $AFTER"; fi

BODY=$(sql "SELECT body FROM notifications WHERE user_id=$PUSER AND event='lab.result_ready' ORDER BY id DESC LIMIT 1")
case "$BODY" in *"are available"*) ok "the message reads properly: $BODY" ;;
                *) bad "unexpected body" "$BODY" ;; esac

INBOX=$(curl -s "${P[@]}" "$BASE/patient/notifications")
echo "$INBOX" | grep -q 'lab.result_ready' && ok "it is in the patient's inbox" || bad "not in the inbox"

# ---------------------------------------------------------------
echo
echo "[3] The five that already worked, still do"
for e in appointment.booked appointment.reminder prescription.issued invoice.issued payment.received; do
  N=$(sql "SELECT COUNT(*) FROM notifications WHERE event='$e'")
  if [ "${N:-0}" -gt 0 ]; then ok "$e has been sent ($N)"; else bad "$e never sent"; fi
done

echo
echo "========================================="
echo "passed: $PASS   failed: $FAIL"
[ "$FAIL" -eq 0 ]
