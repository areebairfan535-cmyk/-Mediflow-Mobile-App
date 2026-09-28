#!/usr/bin/env bash
#
# Run every suite, one after another.
#
#   bash database/run-all-tests.sh
#   bash database/run-all-tests.sh smoke-test.sh test-api.sh    # just these
#
# Sign-ins are rate limited per IP (10 a minute by default —
# RATE_LIMIT_AUTH_MAX). Every suite signs in several times, so two of them
# back to back used to trip the limit and report a 429 inside a test about
# something else entirely — a failure that looks real and is not.
#
# Each suite now clears the bucket before it starts, so the gap below is a
# small courtesy rather than the thing holding it together. Set GAP=0 to run
# them as fast as they will go.
#
# Still true: do not run two copies of this script at once. They share the
# database, and one clearing the limiter mid-way through the other's throttle
# test is exactly the sort of failure nobody can reproduce.
#
# test-mvp.sh needs a consultation happening today:
#   php database/seed_today.php
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG="${LOG:-$HERE/last-run.log}"
GAP="${GAP:-5}"

cd "$HERE" || exit 1

if [ "$#" -gt 0 ]; then
    SUITES=("$@")
else
    SUITES=(
        smoke-test.sh
        smoke-test-patient.sh
        smoke-test-clinical.sh
        smoke-test-billing.sh
        smoke-test-insurance.sh
        smoke-test-ai.sh
        smoke-test-subscription.sh
        smoke-test-platform.sh
        test-location.sh
        test-payment.sh
        test-notify.sh
        test-rx.sh
        test-mvp.sh
        test-settings.sh
        test-claim.sh
        test-billing-engine.sh
        test-compliance.sh
        test-api.sh
        test-schema.sh
        test-security.sh
        test-localization.sh
        test-files.sh
        test-onboarding.sh
        test-presence.sh
        test-push.sh
        test-phases.sh
    )
fi

: > "$LOG"
echo "Running ${#SUITES[@]} suite(s), ${GAP}s apart. Log: $LOG"
echo

for suite in "${SUITES[@]}"; do
    if [ ! -f "$HERE/$suite" ]; then
        echo "  skipped $suite (not found)"
        continue
    fi
    printf '  %-28s ' "$suite"
    echo "=== $suite" >> "$LOG"
    bash "$HERE/$suite" >> "$LOG" 2>&1
    echo "$(grep -E '^passed:' "$LOG" | tail -1)"
    sleep "$GAP"
done

echo "ALL DONE" >> "$LOG"
echo

# One suite per line, then the total. A suite that printed no result at all
# is called out rather than quietly skipped — that is how a crash hides.
# Exits non-zero when anything failed or went missing, so CI goes red.
awk '
    /^=== /    { if (p != "") { print "  NO RESULT: " p; missing++ }; p = $2; next }
    /^passed:/ { printf "  %-28s %4s passed / %s failed\n", p, $2, $4; t += $2; f += $4; p = "" }
    END        { if (p != "") { print "  NO RESULT: " p; missing++ }
                 print ""
                 print "  TOTAL: " t " passed, " f " failed"
                 exit (f > 0 || missing > 0) }
' "$LOG"
