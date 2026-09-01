#!/usr/bin/env bash
# Phase 5 end-to-end test — eligibility, coverage maths, claim lifecycle.
#
#   bash database/smoke-test-insurance.sh
#
# The coverage assertions are exact. Getting a claim's split wrong by a
# percent is money the clinic never sees again.

set -uo pipefail

BASE="${BASE:-http://127.0.0.1:8000/api/v1}"
MYSQL="${MYSQL:-/c/xampp/mysql/bin/mysql.exe}"
DB="${DB:-mediflow}"
PASS=0
FAIL=0

pass() { PASS=$((PASS + 1)); printf '  \033[32mPASS\033[0m  %s\n' "$1"; }
fail() { FAIL=$((FAIL + 1)); printf '  \033[31mFAIL\033[0m  %s\n' "$1"; [ -n "${2:-}" ] && printf '        %s\n' "$2"; }
expect() { if [ "$2" = "$3" ]; then pass "$1 -> $2"; else fail "$1 -> got $2, want $3" "${4:-}"; fi; }
eq()     { if [ "$2" = "$3" ]; then pass "$1 = $2"; else fail "$1: got $2, want $3"; fi; }

reset_limits() { [ -x "$MYSQL" ] && "$MYSQL" -u root "$DB" -e "TRUNCATE TABLE rate_limits;" 2>/dev/null; }
sql() { "$MYSQL" -u root "$DB" -N -e "$1"; }

# Every figure below is checked against a policy that has spent nothing yet:
# the deductible is only applied while coverage_used is zero, and the reserved
# amounts are absolute. A previous run leaves both moved, so the counters go
# back to the seeded baseline before the first assertion rather than after the
# last one — a run that dies part-way must not poison the next.
reset_policies() {
  [ -x "$MYSQL" ] && "$MYSQL" -u root "$DB" -e \
    "UPDATE insurance_policies SET coverage_used = 0 WHERE organization_id = 1;" 2>/dev/null
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
jval()   { printf '%s' "$1" | grep -o "\"$2\":\"[^\"]*\"" | head -1 | sed "s/.*\"$2\":\"//; s/\"$//"; }
jnum()   { printf '%s' "$1" | grep -o "\"$2\":[0-9]*" | head -1 | sed "s/.*://"; }
jmoney() { printf '%s' "$1" | grep -o "\"$2\":\"[0-9.-]*\"" | head -1 | sed "s/.*\":\"//; s/\"$//"; }

# An eligibility response carries BOTH the invoice — which has its own
# insurance_payable column — and the coverage block. Isolate the coverage
# object so an assertion cannot silently read the wrong field.
coverage_of() { printf '%s' "$1" | grep -o '"coverage":{[^}]*}'; }

reset_limits
reset_policies

echo
echo "MediFlow Phase 5 — insurance smoke test"
echo "======================================"

# ---------------------------------------------------------------
echo
echo "[setup] sign in"

R=$(api POST /auth/login '{"email":"owner@clinic.test","password":"Password123"}')
expect "owner login" "$(status_of "$R")" "200"
OWNER=$(jval "$(body_of "$R")" access_token)
OAUTH=(-H "Authorization: Bearer $OWNER")

R=$(api POST /auth/login '{"email":"billing@clinic.test","password":"Password123"}')
BILLING=$(jval "$(body_of "$R")" access_token)
BAUTH=(-H "Authorization: Bearer $BILLING")

R=$(api POST /auth/login '{"email":"reception@clinic.test","password":"Password123"}')
RECEPTION=$(jval "$(body_of "$R")" access_token)
RAUTH=(-H "Authorization: Bearer $RECEPTION")

# Patients seeded with deliberately different policies.
P_COPAY=$(sql "SELECT p.id FROM patients p JOIN insurance_policies i ON i.patient_id=p.id JOIN insurance_providers v ON v.id=i.insurance_provider_id WHERE v.code='SLH' LIMIT 1")
P_DEDUCT=$(sql "SELECT p.id FROM patients p JOIN insurance_policies i ON i.patient_id=p.id JOIN insurance_providers v ON v.id=i.insurance_provider_id WHERE v.code='JBH' LIMIT 1")
P_SMALL=$(sql "SELECT p.id FROM patients p JOIN insurance_policies i ON i.patient_id=p.id JOIN insurance_providers v ON v.id=i.insurance_provider_id WHERE v.code='EFU' LIMIT 1")
P_EXPIRED=$(sql "SELECT p.id FROM patients p JOIN insurance_policies i ON i.patient_id=p.id JOIN insurance_providers v ON v.id=i.insurance_provider_id WHERE v.code='ADH' LIMIT 1")
P_NONE=$(sql "SELECT id FROM patients WHERE organization_id=1 AND id NOT IN (SELECT patient_id FROM insurance_policies) LIMIT 1")

SCALING=$(sql "SELECT id FROM services WHERE code='DENT-SCALE' LIMIT 1")
MRI=$(sql "SELECT id FROM services WHERE code='IMG-MRI' LIMIT 1")

# Raise and issue an invoice, echoing its id.
issue_invoice() {  # $1 patient, $2 service, $3 qty
  local r inv
  r=$(api POST /invoices "{\"patient_id\":$1,\"items\":[{\"service_id\":$2,\"quantity\":$3}]}" "${OAUTH[@]}")
  inv=$(jnum "$(body_of "$r")" id)
  api POST "/invoices/$inv/issue" '' "${OAUTH[@]}" > /dev/null
  printf '%s' "$inv"
}

# ---------------------------------------------------------------
echo
echo "[1] Providers & policies (sec 8)"

R=$(api GET /insurance/providers '' "${OAUTH[@]}")
expect "list insurers" "$(status_of "$R")" "200"
case "$(body_of "$R")" in
  *"State Life Health"*) pass "seeded insurers are listed" ;;
  *)                     fail "no insurers — run seed_insurance.php" ;;
esac

R=$(api GET "/patients/$P_COPAY/policies" '' "${OAUTH[@]}")
expect "patient policies" "$(status_of "$R")" "200"
case "$(body_of "$R")" in
  *SLH-*) pass "policy number returned" ;;
  *)      fail "no policy for the copay patient" ;;
esac

R=$(api POST "/patients/$P_COPAY/policies" '{"insurance_provider_id":999999,"policy_number":"X"}' "${OAUTH[@]}")
expect "unknown insurer refused" "$(status_of "$R")" "422"

R=$(api POST "/patients/$P_COPAY/policies" "{\"insurance_provider_id\":1,\"policy_number\":\"X\",\"valid_from\":\"2026-06-01\",\"valid_to\":\"2026-01-01\"}" "${OAUTH[@]}")
expect "cover ending before it starts refused" "$(status_of "$R")" "422"

R=$(api GET /insurance/providers '' "${RAUTH[@]}")
expect "receptionist may read insurers" "$(status_of "$R")" "200"

R=$(api POST /insurance/providers '{"name":"Sneaky Insurer"}' "${RAUTH[@]}")
expect "receptionist cannot add insurers" "$(status_of "$R")" "403"

# ---------------------------------------------------------------
echo
echo "[2] Eligibility maths (sec 8)"

# 20% copay, no deductible. Scaling 4000 + 17% GST = 4680.
#   copay     = 20% of 4680 = 936.00
#   insurer   = 3744.00
INV_COPAY=$(issue_invoice "$P_COPAY" "$SCALING" 1)
R=$(api GET "/invoices/$INV_COPAY/eligibility" '' "${OAUTH[@]}")
expect "eligibility for a copay policy" "$(status_of "$R")" "200"
B=$(body_of "$R")
eq "billed"        "$(jmoney "$B" billed)"                 "4680.00"
eq "copay 20%"     "$(jmoney "$B" copay_amount)"           "936.00"
eq "insurer pays"  "$(jmoney "$(coverage_of "$B")" insurance_payable)" "3744.00"
eq "patient pays"  "$(jmoney "$B" patient_responsibility)" "936.00"

# 0% copay, PKR 5,000 deductible. 3 x scaling = 12000 + 17% = 14040.
#   deductible = 5000.00 -> 9040.00 left, no copay
#   insurer    = 9040.00, patient = 5000.00
INV_DEDUCT=$(issue_invoice "$P_DEDUCT" "$SCALING" 3)
R=$(api GET "/invoices/$INV_DEDUCT/eligibility" '' "${OAUTH[@]}")
B=$(body_of "$R")
eq "deductible applied" "$(jmoney "$B" deductible_applied)"     "5000.00"
eq "no copay"           "$(jmoney "$B" copay_amount)"           "0.00"
eq "insurer after deductible" "$(jmoney "$(coverage_of "$B")" insurance_payable)" "9040.00"
eq "patient pays the deductible" "$(jmoney "$B" patient_responsibility)" "5000.00"

# 50,000 ceiling, 10% copay. 2 x MRI = 56000 + 17% = 65520.
#   copay   = 6552.00 -> insurer share 58968.00
#   ceiling = 50000 -> capped by 8968.00
#   patient = 65520 - 50000 = 15520.00
INV_CAP=$(issue_invoice "$P_SMALL" "$MRI" 2)
R=$(api GET "/invoices/$INV_CAP/eligibility" '' "${OAUTH[@]}")
B=$(body_of "$R")
eq "billed above the ceiling" "$(jmoney "$B" billed)"            "65520.00"
eq "capped by the ceiling"    "$(jmoney "$B" capped_by_ceiling)" "8968.00"
eq "insurer pays the ceiling" "$(jmoney "$(coverage_of "$B")" insurance_payable)" "50000.00"
eq "patient carries the rest" "$(jmoney "$B" patient_responsibility)" "15520.00"

# Expired policy.
INV_EXPIRED=$(issue_invoice "$P_EXPIRED" "$SCALING" 1)
R=$(api GET "/invoices/$INV_EXPIRED/eligibility" '' "${OAUTH[@]}")
B=$(body_of "$R")
case "$B" in
  *'"eligible":false'*) pass "expired policy is not eligible" ;;
  *)                    fail "expired policy passed eligibility" ;;
esac
case "$B" in
  *expired*) pass "the reason names the expiry" ;;
  *)         fail "no reason given for ineligibility" ;;
esac
eq "patient owes it all" "$(jmoney "$B" patient_responsibility)" "4680.00"

# No policy at all.
if [ -n "$P_NONE" ]; then
  INV_NONE=$(issue_invoice "$P_NONE" "$SCALING" 1)
  R=$(api GET "/invoices/$INV_NONE/eligibility" '' "${OAUTH[@]}")
  case "$(body_of "$R")" in
    *"no insurance policy"*) pass "uninsured patient reported clearly" ;;
    *)                       fail "uninsured case not handled" ;;
  esac
fi

# Pre-treatment quote, before any invoice exists.
R=$(api POST /insurance/check "{\"patient_id\":$P_COPAY,\"amount\":10000}" "${OAUTH[@]}")
expect "pre-treatment quote" "$(status_of "$R")" "200"
B=$(body_of "$R")
eq "quote: insurer" "$(jmoney "$(coverage_of "$B")" insurance_payable)" "8000.00"
eq "quote: patient" "$(jmoney "$B" patient_responsibility)" "2000.00"

# ---------------------------------------------------------------
echo
echo "[3] Raising a claim"

R=$(api POST /claims "{\"invoice_id\":$INV_COPAY}" "${BAUTH[@]}")
expect "billing staff raises a claim" "$(status_of "$R")" "201"
CLAIM=$(jnum "$(body_of "$R")" id)
B=$(body_of "$R")
eq "claimed amount matches the split" "$(jmoney "$B" claimed_amount)" "3744.00"
case "$(jval "$B" claim_no)" in
  CLM-*) pass "claim number allocated" ;;
  *)     fail "no claim number" ;;
esac

R=$(api POST /claims "{\"invoice_id\":$INV_COPAY}" "${BAUTH[@]}")
expect "one live claim per invoice" "$(status_of "$R")" "409"

R=$(api POST /claims "{\"invoice_id\":$INV_EXPIRED}" "${BAUTH[@]}")
expect "cannot claim on an expired policy" "$(status_of "$R")" "409"

# A draft invoice is not claimable.
R=$(api POST /invoices "{\"patient_id\":$P_COPAY,\"items\":[{\"service_id\":$SCALING,\"quantity\":1}]}" "${OAUTH[@]}")
DRAFTINV=$(jnum "$(body_of "$R")" id)
R=$(api POST /claims "{\"invoice_id\":$DRAFTINV}" "${BAUTH[@]}")
expect "cannot claim on a draft invoice" "$(status_of "$R")" "409"

R=$(api POST /claims "{\"invoice_id\":$INV_DEDUCT}" "${RAUTH[@]}")
expect "receptionist cannot raise claims" "$(status_of "$R")" "403"

# ---------------------------------------------------------------
echo
echo "[4] Submission reserves the coverage"

USED_BEFORE=$(sql "SELECT coverage_used FROM insurance_policies WHERE patient_id=$P_COPAY")

R=$(api POST "/claims/$CLAIM/decision" '{"approved_amount":100}' "${BAUTH[@]}")
expect "cannot decide a claim that was never sent" "$(status_of "$R")" "409"

R=$(api POST "/claims/$CLAIM/submit" '{"external_claim_no":"SLH-REF-99001"}' "${BAUTH[@]}")
expect "submit the claim" "$(status_of "$R")" "200"
eq "status" "$(jval "$(body_of "$R")" status)" "submitted"

USED_AFTER=$(sql "SELECT coverage_used FROM insurance_policies WHERE patient_id=$P_COPAY")
eq "coverage reserved on submission" "$USED_AFTER" "3744.00"

R=$(api POST "/claims/$CLAIM/submit" '' "${BAUTH[@]}")
expect "cannot submit twice" "$(status_of "$R")" "409"

R=$(api DELETE "/claims/$CLAIM" '' "${BAUTH[@]}")
expect "cannot delete a submitted claim" "$(status_of "$R")" "409"

# ---------------------------------------------------------------
echo
echo "[5] The insurer decides"

R=$(api POST "/claims/$CLAIM/decision" '{"approved_amount":99999}' "${BAUTH[@]}")
expect "cannot approve more than was claimed" "$(status_of "$R")" "422"

R=$(api POST "/claims/$CLAIM/decision" '{"approved_amount":3000}' "${BAUTH[@]}")
expect "a partial decision needs a reason" "$(status_of "$R")" "422"

R=$(api POST "/claims/$CLAIM/decision" '{"approved_amount":3000,"rejection_code":"NC-02","rejection_reason":"Scaling not covered above PKR 3000"}' "${BAUTH[@]}")
expect "record a partial approval" "$(status_of "$R")" "200"
B=$(body_of "$R")
eq "status"          "$(jval "$B" status)"                    "partially_approved"
eq "approved amount" "$(jmoney "$B" approved_amount)"         "3000.00"
# The 744 the insurer refused falls back on the patient, on top of the 936 copay.
eq "patient carries the shortfall" "$(jmoney "$B" patient_responsibility)" "1680.00"

USED_AFTER=$(sql "SELECT coverage_used FROM insurance_policies WHERE patient_id=$P_COPAY")
eq "unapproved cover released" "$USED_AFTER" "3000.00"

R=$(api GET "/invoices/$INV_COPAY" '' "${OAUTH[@]}")
B=$(body_of "$R")
eq "invoice split: insurer" "$(jmoney "$B" insurance_payable)" "3000.00"
eq "invoice split: patient" "$(jmoney "$B" patient_payable)"   "1680.00"

# ---------------------------------------------------------------
echo
echo "[6] The insurer pays — through the normal ledger"

R=$(api POST "/claims/$CLAIM/paid" '{"amount":99999}' "${BAUTH[@]}")
expect "cannot pay more than approved" "$(status_of "$R")" "422"

R=$(api POST "/claims/$CLAIM/paid" '{"reference":"NEFT-55512"}' "${BAUTH[@]}")
expect "settle the claim" "$(status_of "$R")" "200"
eq "claim status" "$(jval "$(body_of "$R")" status)" "paid"

R=$(api GET "/invoices/$INV_COPAY" '' "${OAUTH[@]}")
B=$(body_of "$R")
eq "invoice paid_total moved"  "$(jmoney "$B" paid_total)"  "3000.00"
eq "balance is the patient's"  "$(jmoney "$B" balance_due)" "1680.00"
eq "invoice now part-paid"     "$(jval "$B" status)"        "partially_paid"
case "$B" in
  *'"method":"insurance"'*) pass "settlement appears as an insurance payment" ;;
  *)                        fail "no insurance payment on the ledger" ;;
esac

R=$(api GET '/payments?method=insurance' '' "${OAUTH[@]}")
case "$(body_of "$R")" in
  *NEFT-55512*) pass "the insurer's reference is on the payment" ;;
  *)            fail "reference missing from the ledger" ;;
esac

R=$(api POST "/claims/$CLAIM/paid" '' "${BAUTH[@]}")
expect "cannot pay a settled claim twice" "$(status_of "$R")" "409"

# ---------------------------------------------------------------
echo
echo "[7] Rejection and resubmission (sec 8)"

R=$(api POST /claims "{\"invoice_id\":$INV_DEDUCT}" "${BAUTH[@]}")
expect "raise a second claim" "$(status_of "$R")" "201"
CLAIM2=$(jnum "$(body_of "$R")" id)

api POST "/claims/$CLAIM2/submit" '' "${BAUTH[@]}" > /dev/null
USED=$(sql "SELECT coverage_used FROM insurance_policies WHERE patient_id=$P_DEDUCT")
eq "cover reserved" "$USED" "9040.00"

R=$(api POST "/claims/$CLAIM2/processing" '' "${BAUTH[@]}")
expect "insurer acknowledges" "$(status_of "$R")" "200"

R=$(api POST "/claims/$CLAIM2/decision" '{"approved_amount":0,"rejection_code":"DOC-01","rejection_reason":"Discharge summary not attached"}' "${BAUTH[@]}")
expect "record a full rejection" "$(status_of "$R")" "200"
eq "status" "$(jval "$(body_of "$R")" status)" "rejected"

USED=$(sql "SELECT coverage_used FROM insurance_policies WHERE patient_id=$P_DEDUCT")
eq "rejection released the whole reservation" "$USED" "0.00"

R=$(api POST "/claims/$CLAIM2/resubmit" '' "${BAUTH[@]}")
expect "resubmit the rejected claim" "$(status_of "$R")" "201"
CLAIM3=$(jnum "$(body_of "$R")" id)
eq "new claim is a resubmission" "$(jval "$(body_of "$R")" status)" "resubmission"

R=$(api GET "/claims/$CLAIM2" '' "${BAUTH[@]}")
case "$(body_of "$R")" in
  *"Discharge summary not attached"*) pass "the rejection reason is kept for analytics" ;;
  *)                                   fail "rejection reason lost" ;;
esac
case "$(body_of "$R")" in
  *'"resubmissions"'*) pass "the rejected claim links to its replacement" ;;
  *)                   fail "resubmission chain missing" ;;
esac

R=$(api POST "/claims/$CLAIM/resubmit" '' "${BAUTH[@]}")
expect "cannot resubmit a paid claim" "$(status_of "$R")" "409"

R=$(api POST "/claims/$CLAIM3/submit" '' "${BAUTH[@]}")
expect "the resubmission can be sent" "$(status_of "$R")" "200"

# ---------------------------------------------------------------
echo
echo "[8] Pipeline & rejection analytics (sec 8)"

R=$(api GET /claims/pipeline '' "${OAUTH[@]}")
expect "claims pipeline" "$(status_of "$R")" "200"
B=$(body_of "$R")
for key in by_status outstanding settled rejections; do
  case "$B" in
    *"\"$key\""*) pass "pipeline reports $key" ;;
    *)            fail "pipeline missing $key" ;;
  esac
done
case "$B" in
  *DOC-01*) pass "rejection reasons feed the analytics" ;;
  *)        fail "rejection codes not analysed" ;;
esac

# The static path must not be swallowed by /claims/{id}.
R=$(api GET /claims/pipeline '' "${OAUTH[@]}")
expect "static route beats the {id} pattern" "$(status_of "$R")" "200"

R=$(api GET '/claims?open=1' '' "${OAUTH[@]}")
expect "open claims filter" "$(status_of "$R")" "200"

# ---------------------------------------------------------------
echo
echo "[9] Audit (sec 16)"

R=$(api GET '/audit-logs?resource_type=claim' '' "${OAUTH[@]}")
case "$(body_of "$R")" in
  *'"resource_type":"claim"'*) pass "claim events audited" ;;
  *)                           fail "claims missing from the trail" ;;
esac

R=$(api GET '/audit-logs?resource_type=insurance_policy' '' "${OAUTH[@]}")
expect "policy trail readable" "$(status_of "$R")" "200"

# ---------------------------------------------------------------
echo
echo "======================================"
printf 'passed: %d   failed: %d\n\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ] || exit 1
