#!/usr/bin/env bash
# Phase 6 end-to-end test — the AI module (§9).
#
#   bash database/smoke-test-ai.sh
#
# This suite runs WITHOUT an AI provider configured, and that is the point.
# §26 keeps AI outside the MVP, so "no provider" is a supported state: every
# assistant must fail cleanly and nothing else may break.
#
# The approval gates are tested with a hand-written note, so §9's
# "human confirmation" rule is verified whether or not a key is present.

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

# Section 7 switches the API to the offline stub provider by editing .env, so
# a copy is kept and put back on any exit — including a failure part-way.
ENV_FILE="${ENV_FILE:-$(cd "$(dirname "$0")/.." && pwd)/.env}"
ENV_BACKUP=""

restore_env() {
  if [ -n "$ENV_BACKUP" ] && [ -f "$ENV_BACKUP" ]; then
    cp "$ENV_BACKUP" "$ENV_FILE"
    rm -f "$ENV_BACKUP"
    ENV_BACKUP=""
  fi
}
trap restore_env EXIT

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
echo "MediFlow Phase 6 — AI module smoke test"
echo "======================================="

# ---------------------------------------------------------------
echo
echo "[setup] sign in"

R=$(api POST /auth/login '{"email":"owner@clinic.test","password":"Password123"}')
expect "owner login" "$(status_of "$R")" "200"
OWNER=$(jval "$(body_of "$R")" access_token)
OAUTH=(-H "Authorization: Bearer $OWNER")

R=$(api POST /auth/login '{"email":"doctor@clinic.test","password":"Password123"}')
DOC=$(jval "$(body_of "$R")" access_token)
DAUTH=(-H "Authorization: Bearer $DOC")

R=$(api POST /auth/login '{"email":"reception@clinic.test","password":"Password123"}')
RECEPTION=$(jval "$(body_of "$R")" access_token)
RAUTH=(-H "Authorization: Bearer $RECEPTION")

R=$(api POST /auth/login '{"email":"patient@demo.test","password":"Password123"}')
PATIENT=$(jval "$(body_of "$R")" access_token)
PAUTH=(-H "Authorization: Bearer $PATIENT")

# ---------------------------------------------------------------
echo
echo "[1] Status reporting (sec 9, sec 26)"

R=$(api GET /ai/status '' "${OAUTH[@]}")
expect "status endpoint" "$(status_of "$R")" "200"
B=$(body_of "$R")

case "$B" in
  *'"configured":false'*) pass "reports itself as not configured" ;;
  *'"configured":true'*)  pass "a provider IS configured — the graceful-failure checks will be skipped" ;;
  *)                      fail "status does not report configuration" ;;
esac

CONFIGURED=$(printf '%s' "$B" | grep -o '"configured":[a-z]*' | sed 's/.*://')

# "Configured" and "configured with a real model" are different states, and
# section 7 needs to tell them apart: the stub can be asserted against, a real
# provider cannot (its output is not deterministic, and calling it costs money).
case "$B" in *'"provider":"stub'*) STUBBED=yes ;; *) STUBBED=no ;; esac

for key in documentation billing claim; do
  case "$B" in
    *"\"$key\""*) pass "advertises the $key assistant" ;;
    *)            fail "$key assistant missing from status" ;;
  esac
done

# §9's rule must be stated in the contract, not just in the code.
case "$B" in
  *"must review and approve"*) pass "status states the human-approval rule" ;;
  *)                           fail "approval policy not surfaced" ;;
esac

# ---------------------------------------------------------------
echo
echo "[2] Graceful failure with no provider (sec 26)"

ENC_OPEN=$(sql "SELECT id FROM encounters WHERE status='open' AND organization_id=1 LIMIT 1")

if [ -z "$ENC_OPEN" ]; then
  # Open one so the drafting path can be exercised.
  PATIENT_ID=$(sql "SELECT id FROM patients WHERE organization_id=1 LIMIT 1")
  DOCTOR_ID=$(sql "SELECT id FROM doctors WHERE organization_id=1 ORDER BY id LIMIT 1")
  R=$(api POST /encounters "{\"patient_id\":$PATIENT_ID,\"doctor_id\":$DOCTOR_ID,\"chief_complaint\":\"AI test visit\"}" "${DAUTH[@]}")
  ENC_OPEN=$(jnum "$(body_of "$R")" id)
fi

[ -n "$ENC_OPEN" ] && pass "have an open consultation ($ENC_OPEN)" || fail "no open consultation to test with"

R=$(api POST "/encounters/$ENC_OPEN/ai/draft-note" '{"shorthand":"toothache 3d, tender #26, no swelling"}' "${OAUTH[@]}")
CODE=$(status_of "$R")

if [ "$CONFIGURED" = "false" ]; then
  expect "draft-note fails cleanly" "$CODE" "503"
  case "$(body_of "$R")" in
    *"not configured"*|*"no API key"*|*"backend/.env"*)
      pass "the error explains how to enable it" ;;
    *) fail "unhelpful error" "$(body_of "$R")" ;;
  esac
  case "$(body_of "$R")" in
    *'"code":"ai_unavailable"'*) pass "typed as ai_unavailable, not a generic 500" ;;
    *)                           fail "wrong error code" ;;
  esac

  R=$(api GET "/encounters/$ENC_OPEN/ai/billing-suggestions" '' "${OAUTH[@]}")
  expect "billing suggestions fail cleanly" "$(status_of "$R")" "503"
else
  expect "draft-note succeeds with a provider" "$CODE" "201"
fi

# The critical property: an unusable AI must not break the clinic.
R=$(api GET "/encounters/$ENC_OPEN" '' "${DAUTH[@]}")
expect "the consultation still loads" "$(status_of "$R")" "200"

R=$(api POST "/encounters/$ENC_OPEN/diagnoses" '{"description":"Irreversible pulpitis","icd10_code":"K04.0"}' "${DAUTH[@]}")
expect "clinical work still possible" "$(status_of "$R")" "201"

R=$(api GET /patients '' "${DAUTH[@]}")
expect "the rest of the API is unaffected" "$(status_of "$R")" "200"

# ---------------------------------------------------------------
echo
echo "[3] Human approval is structural (sec 9)"

# A note written by hand exercises the same approval gates the AI draft uses.
R=$(api POST "/encounters/$ENC_OPEN/notes" '{"body":"Hand-written note for the approval test.","type":"soap"}' "${DAUTH[@]}")
expect "create a note" "$(status_of "$R")" "201"
NOTE=$(jnum "$(body_of "$R")" id)

UNAPPROVED=$(sql "SELECT approved_by IS NULL FROM clinical_notes WHERE id=$NOTE")
[ "$UNAPPROVED" = "1" ] && pass "a new note starts unapproved" || fail "note was auto-approved"

R=$(api POST "/clinical-notes/$NOTE/approve" '{"body":"Reviewed and corrected by the clinician."}' "${DAUTH[@]}")
expect "clinician approves it" "$(status_of "$R")" "200"

APPROVER=$(sql "SELECT approved_by FROM clinical_notes WHERE id=$NOTE")
[ -n "$APPROVER" ] && [ "$APPROVER" != "NULL" ] \
  && pass "the approver is recorded ($APPROVER)" \
  || fail "no approver recorded"

EDITED=$(sql "SELECT body LIKE '%corrected by the clinician%' FROM clinical_notes WHERE id=$NOTE")
[ "$EDITED" = "1" ] && pass "the clinician's edit was kept, not the original" || fail "edit not saved"

R=$(api POST "/clinical-notes/$NOTE/approve" '{}' "${DAUTH[@]}")
expect "cannot approve twice" "$(status_of "$R")" "409"

R=$(api DELETE "/clinical-notes/$NOTE" '' "${DAUTH[@]}")
expect "an approved note cannot be discarded" "$(status_of "$R")" "409"

# A draft, by contrast, can be thrown away.
R=$(api POST "/encounters/$ENC_OPEN/notes" '{"body":"Draft to discard."}' "${DAUTH[@]}")
DRAFT=$(jnum "$(body_of "$R")" id)
R=$(api DELETE "/clinical-notes/$DRAFT" '' "${DAUTH[@]}")
expect "an unapproved draft can be discarded" "$(status_of "$R")" "200"

# ---------------------------------------------------------------
echo
echo "[4] Permissions (sec 11)"

R=$(api GET /ai/status '' "${RAUTH[@]}")
expect "receptionist may read AI status" "$(status_of "$R")" "200"

R=$(api POST "/encounters/$ENC_OPEN/ai/draft-note" '{"shorthand":"test"}' "${RAUTH[@]}")
expect "receptionist cannot draft clinical notes" "$(status_of "$R")" "403"

R=$(api GET "/encounters/$ENC_OPEN/ai/billing-suggestions" '' "${RAUTH[@]}")
expect "receptionist cannot use the billing assistant" "$(status_of "$R")" "403"

R=$(api GET /ai/status '' "${PAUTH[@]}")
expect "patient sees AI status but nothing else" "$(status_of "$R")" "200"

R=$(api POST "/encounters/$ENC_OPEN/ai/draft-note" '{"shorthand":"test"}' "${PAUTH[@]}")
expect "patient cannot touch the assistants" "$(status_of "$R")" "403"

CLAIM=$(sql "SELECT id FROM claims WHERE organization_id=1 LIMIT 1")
if [ -n "$CLAIM" ]; then
  R=$(api GET "/claims/$CLAIM/ai/review" '' "${RAUTH[@]}")
  expect "receptionist cannot run the claim assistant" "$(status_of "$R")" "403"
fi

# ---------------------------------------------------------------
echo
echo "[5] Validation"

R=$(api POST "/encounters/$ENC_OPEN/ai/draft-note" '{}' "${OAUTH[@]}")
expect "shorthand is required" "$(status_of "$R")" "422"

R=$(api POST "/encounters/$ENC_OPEN/ai/draft-note" '{"shorthand":"ab"}' "${OAUTH[@]}")
expect "two characters is not a note" "$(status_of "$R")" "422"

R=$(api POST "/encounters/999999/ai/draft-note" '{"shorthand":"valid shorthand here"}' "${OAUTH[@]}")
expect "unknown encounter is 404" "$(status_of "$R")" "404"

# A completed consultation is a finished record.
ENC_DONE=$(sql "SELECT id FROM encounters WHERE status='completed' AND organization_id=1 LIMIT 1")
if [ -n "$ENC_DONE" ]; then
  R=$(api POST "/encounters/$ENC_DONE/ai/draft-note" '{"shorthand":"late addition"}' "${OAUTH[@]}")
  expect "cannot draft onto a completed consultation" "$(status_of "$R")" "409"
fi

# ---------------------------------------------------------------
echo
echo "[6] Audit (sec 16)"

R=$(api GET '/audit-logs?resource_type=clinical_note' '' "${OAUTH[@]}")
case "$(body_of "$R")" in
  *'"resource_type":"clinical_note"'*) pass "note approvals are audited" ;;
  *)                                   fail "approvals missing from the trail" ;;
esac

# ---------------------------------------------------------------
# Everything above proves the module fails well. This proves it works —
# through the offline stub provider (AI_PROVIDER=stub), because the approval
# gates are the safety-critical part of §9 and they cannot sit untested until
# somebody buys an API key.
#
# The .env is edited and restored; the EXIT trap puts it back even if a call
# below kills the script part-way.
echo
echo "[7] The configured path, via the offline stub provider"

if [ "$CONFIGURED" != "false" ] && [ "$STUBBED" != "yes" ]; then
  echo "  (a real provider is configured — its output is not asserted here)"
elif [ "$CONFIGURED" = "false" ] && [ ! -w "$ENV_FILE" ]; then
  echo "  (no writable .env at $ENV_FILE — stub section skipped)"
else
  # Already on the stub (a developer left AI_PROVIDER=stub in .env)? Then run
  # the assertions against it as-is and leave the file alone. Only switch the
  # provider on when nothing is configured at all.
  if [ "$CONFIGURED" = "false" ]; then
    ENV_BACKUP="$ENV_FILE.smoke-backup"
    cp "$ENV_FILE" "$ENV_BACKUP"
    printf '\nAI_PROVIDER=stub\n' >> "$ENV_FILE"
  fi

  R=$(api GET /ai/status '' "${OAUTH[@]}")
  case "$(body_of "$R")" in
    *'"configured":true'*) pass "the stub provider reports as configured" ;;
    *)                     fail "stub provider not picked up" "$(body_of "$R")" ;;
  esac
  case "$(body_of "$R")" in
    *stub*) pass "status names the stub, so nobody mistakes it for a model" ;;
    *)      fail "status hides which provider answered" ;;
  esac

  R=$(api POST "/encounters/$ENC_OPEN/ai/draft-note" \
      '{"shorthand":"toothache 3d, tender #26, no swelling, amox 500 tds 5d"}' "${DAUTH[@]}")
  expect "the assistant drafts a note" "$(status_of "$R")" "201"
  AI_NOTE=$(jnum "$(body_of "$R")" id)

  if [ -n "$AI_NOTE" ]; then
    DRAFTED=$(sql "SELECT is_ai_drafted FROM clinical_notes WHERE id=$AI_NOTE")
    [ "$DRAFTED" = "1" ] && pass "the note is marked as AI-drafted" \
                         || fail "AI authorship not recorded"

    PENDING=$(sql "SELECT approved_by IS NULL FROM clinical_notes WHERE id=$AI_NOTE")
    [ "$PENDING" = "1" ] && pass "AI output lands unapproved — sec 9's whole point" \
                         || fail "the AI wrote straight into the record"

    SOAP=$(sql "SELECT body LIKE '%Subjective%' FROM clinical_notes WHERE id=$AI_NOTE")
    [ "$SOAP" = "1" ] && pass "the draft is structured as SOAP" || fail "draft is not structured"

    R=$(api POST "/clinical-notes/$AI_NOTE/approve" '{}' "${DAUTH[@]}")
    expect "a clinician approves the AI draft" "$(status_of "$R")" "200"

    APPROVER=$(sql "SELECT approved_by FROM clinical_notes WHERE id=$AI_NOTE")
    [ -n "$APPROVER" ] && [ "$APPROVER" != "NULL" ] \
      && pass "the approving human is on the record ($APPROVER)" \
      || fail "no approver recorded for the AI draft"
  fi

  INV_BEFORE=$(sql "SELECT COUNT(*) FROM invoices WHERE organization_id=1")

  R=$(api GET "/encounters/$ENC_OPEN/ai/billing-suggestions" '' "${OAUTH[@]}")
  expect "the billing assistant answers" "$(status_of "$R")" "200"
  case "$(body_of "$R")" in
    *'"requires_approval":true'*) pass "suggestions are flagged as needing approval" ;;
    *)                            fail "billing suggestions not marked advisory" ;;
  esac
  case "$(body_of "$R")" in
    *'"estimated_total"'*) pass "an estimate is returned, priced from the catalogue" ;;
    *)                     fail "no estimate returned" ;;
  esac

  INV_AFTER=$(sql "SELECT COUNT(*) FROM invoices WHERE organization_id=1")
  [ "$INV_BEFORE" = "$INV_AFTER" ] \
    && pass "asking for suggestions billed nothing by itself" \
    || fail "the billing assistant created an invoice on its own"

  if [ -n "$CLAIM" ]; then
    STATUS_BEFORE=$(sql "SELECT status FROM claims WHERE id=$CLAIM")

    R=$(api GET "/claims/$CLAIM/ai/review" '' "${OAUTH[@]}")
    expect "the claim assistant answers" "$(status_of "$R")" "200"
    case "$(body_of "$R")" in
      *'"advisory_only":true'*) pass "the review states it is advisory" ;;
      *)                        fail "review not marked advisory" ;;
    esac

    STATUS_AFTER=$(sql "SELECT status FROM claims WHERE id=$CLAIM")
    [ "$STATUS_BEFORE" = "$STATUS_AFTER" ] \
      && pass "reviewing did not move the claim ($STATUS_AFTER)" \
      || fail "the claim status changed during an advisory review"
  fi

  R=$(api GET '/audit-logs?resource_type=ai_billing_suggestion' '' "${OAUTH[@]}")
  case "$(body_of "$R")" in
    *'"resource_type":"ai_billing_suggestion"'*) pass "assistant use is audited" ;;
    *)                                           fail "AI use missing from the trail" ;;
  esac

  # §25 Phase 6 also names a patient summary. Same rule as the rest of the
  # module: advisory, and it never becomes part of the record.
  PID=$(sql "SELECT id FROM patients WHERE organization_id=1 LIMIT 1")
  NOTES_BEFORE=$(sql "SELECT COUNT(*) FROM clinical_notes WHERE patient_id=$PID")

  R=$(api GET "/patients/$PID/ai/summary" '' "${DAUTH[@]}")
  expect "the patient summary answers" "$(status_of "$R")" "200"
  case "$(body_of "$R")" in
    *'"advisory_only":true'*) pass "and says it is advisory" ;;
    *)                        fail "summary not marked advisory" ;;
  esac
  case "$(body_of "$R")" in
    *'"watch_for"'*) pass "it surfaces what to check before prescribing" ;;
    *)               fail "no watch_for block" ;;
  esac

  NOTES_AFTER=$(sql "SELECT COUNT(*) FROM clinical_notes WHERE patient_id=$PID")
  [ "$NOTES_BEFORE" = "$NOTES_AFTER" ] \
    && pass "summarising wrote nothing into the chart" \
    || fail "the summary created a clinical note"

  R=$(api GET "/patients/$PID/ai/summary" '' "${RAUTH[@]}")
  expect "receptionist cannot summarise a chart" "$(status_of "$R")" "403"

  # Only assert the restore if this suite did the switching. A .env that
  # already said stub must be left exactly as its owner wrote it.
  if [ -n "$ENV_BACKUP" ]; then
    restore_env
    R=$(api GET /ai/status '' "${OAUTH[@]}")
    case "$(body_of "$R")" in
      *'"configured":false'*) pass ".env restored — back to no provider" ;;
      *)                      fail ".env was not restored" ;;
    esac
  else
    pass ".env left untouched — it already selected the stub"
  fi
fi

# ---------------------------------------------------------------
# §25 lists an "intelligent search" in the AI phase. It is answered by SQL,
# not by a model — which is why it is asserted OUTSIDE the stub section: it
# has to work whether or not a provider is configured.
echo
echo "[8] Search across the record (sec 25)"

R=$(api GET '/search?q=a' '' "${DAUTH[@]}")
expect "one character is not a search" "$(status_of "$R")" "422"

R=$(api GET '/search?q=Fatima' '' "${DAUTH[@]}")
expect "search by name" "$(status_of "$R")" "200"
case "$(body_of "$R")" in
  *'"patients":[{'*) pass "it finds the patient" ;;
  *)                   fail "no patient matched a name that exists" ;;
esac

# The thing the patient list cannot do: find people by what was diagnosed.
R=$(api GET '/search?q=pulpitis' '' "${DAUTH[@]}")
case "$(body_of "$R")" in
  *'"diagnoses":[{'*) pass "and by diagnosis, across every visit" ;;
  *)                    fail "diagnosis search found nothing" ;;
esac

INV_NO=$(sql "SELECT invoice_no FROM invoices WHERE organization_id=1 AND status <> ''draft'' LIMIT 1")
if [ -n "$INV_NO" ]; then
  R=$(api GET "/search?q=$INV_NO" '' "${DAUTH[@]}")
  case "$(body_of "$R")" in
    *"$INV_NO"*) pass "and by document number ($INV_NO)" ;;
    *)           fail "invoice number did not match itself" ;;
  esac
fi

# Tenant isolation applies to search like everything else.
OTHER_PATIENT=$(sql "SELECT first_name FROM patients WHERE organization_id <> 1 LIMIT 1")
if [ -n "$OTHER_PATIENT" ]; then
  R=$(api GET "/search?q=$OTHER_PATIENT" '' "${DAUTH[@]}")
  case "$(body_of "$R")" in
    *"\"total\":0"*) pass "another clinic's records are not searchable" ;;
    *)               fail "search reached across tenants" ;;
  esac
fi

R=$(api GET '/search?q=Fatima' '' "${PAUTH[@]}")
expect "a patient cannot search the clinic" "$(status_of "$R")" "403"

# ---------------------------------------------------------------
echo
echo "======================================="
printf 'passed: %d   failed: %d\n\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ] || exit 1
