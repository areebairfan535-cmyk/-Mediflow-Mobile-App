<?php
declare(strict_types=1);

/**
 * Put the demo database back the way seed.php meant it.
 *
 *   php database/reset-demo.php          # say what would go
 *   php database/reset-demo.php --apply  # actually remove it
 *
 * The smoke tests register accounts to prove things about them — that a new
 * user belongs to nowhere, that one tenant cannot reach another, that an
 * invite is a paid seat. They do not remove those accounts afterwards, so
 * every run leaves a few more behind in the demo clinic.
 *
 * That is harmless until it is not. Professional allows fifty staff; once the
 * leftovers push the clinic past it, the suite's own "downgrade back to
 * Professional" step is refused — correctly — and the clinic is left on
 * Enterprise. Every later run then opens on the wrong plan and reports
 * failures that are about the database, not the code.
 *
 * So this removes the accounts the tests made, and nothing else. They are
 * recognisable: the suites build their addresses from a unix timestamp, so a
 * long run of digits in the local part is the signature. Anything without one
 * — the seeded staff, the demo patient, a real person who signed in — stays.
 */

require __DIR__ . '/../bootstrap/app.php';

use App\Core\Database;

$apply = in_array('--apply', $argv, true);

/**
 * A 9-or-more digit run is a unix timestamp, which is how every suite builds
 * the names it makes up. Nothing a person types looks like this.
 */
const TEST_STAMP = '/[0-9]{9,}/';

/** Emails with a test timestamp in the local part. */
const TEST_EMAIL = '/^[^@]*[0-9]{9,}[^@]*@/';

$users = Database::select('SELECT id, name, email, created_at FROM users ORDER BY id');

$doomed = [];
$kept   = [];
foreach ($users as $user) {
    if (preg_match(TEST_EMAIL, (string) $user['email']) === 1) {
        $doomed[] = $user;
    } else {
        $kept[] = $user;
    }
}

/**
 * The organizations the suites onboard.
 *
 * §22 is tested by registering a clinic and checking what it can and cannot
 * reach, so every run leaves another "Limit Clinic 1788895470" behind. They are
 * harmless individually and ruinous in aggregate: the super admin panel counts
 * tenants, and a demo that opens on 169 of them, 165 of which are called
 * "Bad Plan Clinic", is not a demo of anything.
 *
 * Deleting one takes its rows with it — the schema cascades from organizations
 * — which is exactly right for a clinic that never existed.
 */
$orgs = Database::select('SELECT id, name, slug FROM organizations ORDER BY id');

$deadOrgs = [];
$liveOrgs = [];
foreach ($orgs as $org) {
    if (preg_match(TEST_STAMP, (string) $org['slug']) === 1) {
        $deadOrgs[] = $org;
    } else {
        $liveOrgs[] = $org;
    }
}

/**
 * And the services the billing tests invent.
 *
 * "UI-1788898445 · Catalogue test" and its seventy-odd siblings sit in the same
 * list a receptionist picks a consultation from. The real catalogue is
 * twenty-seven services; the screen was showing a hundred and five.
 *
 * Only ones nothing has been billed for. A service that appears on an issued
 * invoice stays, junk name or not — deleting it would leave a line item
 * pointing at a service that no longer exists, and the invoice is the record.
 */
$deadServices = Database::select(
    'SELECT s.id, s.code, s.name
       FROM services s
      WHERE s.code REGEXP :stamp
        AND NOT EXISTS (SELECT 1 FROM invoice_items ii WHERE ii.service_id = s.id)
      ORDER BY s.id',
    ['stamp' => '[0-9]{9,}'],
);

$show = static function (string $what, array $rows, string $field): void {
    echo count($rows) . " $what:\n";
    foreach (array_slice($rows, 0, 8) as $row) {
        echo '  ' . $row[$field] . "\n";
    }
    if (count($rows) > 8) {
        echo '  … and ' . (count($rows) - 8) . " more\n";
    }
};

echo "\n";
echo "Keeping " . count($kept) . " account(s):\n";
foreach ($kept as $user) {
    echo '  ' . $user['email'] . "\n";
}
echo "\n";
$show('clinic(s) kept', $liveOrgs, 'name');

echo "\n";
if ($doomed === [] && $deadOrgs === [] && $deadServices === []) {
    echo "No test leftovers found. Nothing to do.\n\n";
    exit(0);
}

if (!$apply) {
    echo "Dry run — pass --apply to remove:\n\n";
}
$show('test account(s)', $doomed, 'email');
echo "\n";
$show('test clinic(s)', $deadOrgs, 'name');
echo "\n";
$show('test service(s), none of them billed', $deadServices, 'code');

if (!$apply) {
    echo "\nNothing was changed.\n\n";
    exit(0);
}

$unlinked = $members = $deleted = $orgsGone = $servicesGone = 0;

if ($doomed !== []) {
    $in = implode(',', array_map(static fn (array $u): int => (int) $u['id'], $doomed));

    // A patient row outlives its login — the chart is the clinic's record, not
    // the account's. Unlink rather than delete, or removing a stale test
    // account would take a medical history with it.
    $unlinked = Database::statement("UPDATE patients SET user_id = NULL WHERE user_id IN ($in)");
    $members  = Database::statement("DELETE FROM organization_users WHERE user_id IN ($in)");
    $deleted  = Database::statement("DELETE FROM users WHERE id IN ($in)");
}

if ($deadOrgs !== []) {
    // Everything under an organization cascades away with it, which is what
    // should happen to a clinic that only ever existed for one assertion.
    $in = implode(',', array_map(static fn (array $o): int => (int) $o['id'], $deadOrgs));
    $orgsGone = Database::statement("DELETE FROM organizations WHERE id IN ($in)");
}

if ($deadServices !== []) {
    // Prices first — a service_prices row points at the service, and the
    // constraint is there to stop exactly this kind of orphan.
    $in = implode(',', array_map(static fn (array $s): int => (int) $s['id'], $deadServices));
    Database::statement("DELETE FROM service_prices WHERE service_id IN ($in)");
    $servicesGone = Database::statement("DELETE FROM services WHERE id IN ($in)");
}

echo "\n";
echo "  unlinked  $unlinked patient chart(s)\n";
echo "  removed   $members membership(s)\n";
echo "  removed   $deleted account(s)\n";
echo "  removed   $orgsGone test clinic(s)\n";
echo "  removed   $servicesGone test service(s)\n";
echo "\nRun database/seed.php next to put the demo clinic back on its plan.\n\n";
