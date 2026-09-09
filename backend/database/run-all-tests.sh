#!/usr/bin/env bash
#
# Run every suite, one after another.
#
#   bash database/run-all-tests.sh
#   bash database/run-all-tests.sh smoke-test.sh test-api.sh    # just these
#
# The 35-second gap between suites is not politeness. Sign-ins are rate
# limited per IP (10 a minute by default — RATE_LIMIT_AUTH_MAX), every suite
# signs in several times, and two suites back to back trip the limit. That
# surfaces as a 429 inside a test which has nothing to do with rate limiting
# and looks exactly like a real failure. For the same reason: never run two
# copies of this script at once.
#
# test-mvp.sh needs a consultation happening today:
#   php database/seed_today.php
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG="${LOG:-$HERE/last-run.log}"
GAP="${GAP:-35}"

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
awk '
    /^=== /    { if (p != "") print "  NO RESULT: " p; p = $2; next }
    /^passed:/ { printf "  %-28s %4s passed / %s failed\n", p, $2, $4; t += $2; f += $4; p = "" }
    END        { if (p != "") print "  NO RESULT: " p
                 print ""
                 print "  TOTAL: " t " passed, " f " failed" }
' "$LOG"
