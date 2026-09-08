<?php
declare(strict_types=1);

/**
 * A day in the demo clinic — today, whenever today is.
 *
 *   php database/seed_today.php
 *
 * The doctor dashboard (§4) answers questions about the current day: who is
 * waiting, what is finished, what was cancelled, what has been billed. On a
 * fresh install every one of those is zero, because the other seeders write
 * history rather than a morning — so the screen that has the most on it is the
 * one that shows nothing at all.
 *
 * Re-runnable, and re-runnable is the point. A day seeded last week is history
 * by Tuesday and the dashboard is empty again, so this clears the demo doctor's
 * appointments for today and lays out a fresh one. It touches a single doctor
 * on a single day and nothing else.
 *
 * Requires seed.php and seed_clinical.php to have run first.
 */

if (PHP_SAPI !== 'cli') {
    exit("This script must be run from the command line.\n");
}

require dirname(__DIR__) . '/bootstrap/app.php';

use App\Core\Database;

echo "Seeding today's clinic day\n==========================\n";

$org = Database::selectOne("SELECT * FROM organizations WHERE slug = 'demo-clinic'");
if ($org === null) {
    exit("Demo clinic not found. Run: php database/seed.php\n");
}
$orgId = (int) $org['id'];

$doctor = Database::selectOne(
    'SELECT d.id, u.name
       FROM doctors d JOIN users u ON u.id = d.user_id
      WHERE d.organization_id = :org AND u.email = :email',
    ['org' => $orgId, 'email' => 'doctor@clinic.test'],
);
if ($doctor === null) {
    exit("The demo doctor is missing. Run: php database/seed.php\n");
}
$doctorId = (int) $doctor['id'];

$patients = Database::select(
    'SELECT id, first_name, last_name FROM patients
      WHERE organization_id = :org AND status = :status
      ORDER BY id LIMIT 6',
    ['org' => $orgId, 'status' => 'active'],
);
if (count($patients) < 4) {
    exit("Not enough patients. Run: php database/seed_clinical.php\n");
}

/**
 * The patient with the phone app goes in the chair, not the finished pile.
 *
 * Only one demo patient has a login, and the whole point of the walkthrough is
 * to consult with them and watch it appear on their phone. Left to the plain
 * ordering they landed on the 09:00 slot, which the day marks completed — so
 * the one consultation worth starting was the one that could not be started.
 *
 * DEMO_SLOT is the index of the 'arrived' appointment below.
 */
const DEMO_SLOT = 3;

$appPatient = Database::selectOne(
    'SELECT p.id FROM patients p
       JOIN users u ON u.id = p.user_id
      WHERE p.organization_id = :org AND u.email = :email',
    ['org' => $orgId, 'email' => 'patient@demo.test'],
);
if ($appPatient !== null) {
    $index = null;
    foreach ($patients as $i => $p) {
        if ((int) $p['id'] === (int) $appPatient['id']) {
            $index = $i;
            break;
        }
    }
    if ($index !== null && $index !== DEMO_SLOT) {
        [$patients[$index], $patients[DEMO_SLOT]] = [$patients[DEMO_SLOT], $patients[$index]];
    }
}

/**
 * Times are the clinic's own wall clock; the column is UTC, so they are
 * converted the same way the booking code does it (§23).
 */
$settings = (new \App\Repositories\OrganizationRepository())->settings($orgId);
$tz  = new DateTimeZone((string) ($settings['timezone'] ?? 'UTC'));
$utc = new DateTimeZone('UTC');

$today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
$at    = static fn (string $hhmm): string =>
    (new DateTimeImmutable("$today $hhmm", $tz))->setTimezone($utc)->format('Y-m-d H:i:s');

$dayStart = $at('00:00');
$dayEnd   = (new DateTimeImmutable("$today 00:00", $tz))
    ->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');

// Clear this doctor's day before laying out a new one. Scoped to one doctor
// and one day rather than looking for a marker in the reason text — a marker
// would have to be stored somewhere the clinic app displays, and "[demo day]"
// printed next to a patient's name is worse than the problem it solves.
//
// Encounters are unhooked first: they point at the appointments, and deleting
// the appointments out from under them would leave consultations attached to
// nothing.
$old = Database::select(
    'SELECT id FROM appointments
      WHERE organization_id = :org AND doctor_id = :did
        AND scheduled_at >= :from AND scheduled_at < :to',
    ['org' => $orgId, 'did' => $doctorId, 'from' => $dayStart, 'to' => $dayEnd],
);
if ($old !== []) {
    $ids = implode(',', array_map(static fn (array $a): int => (int) $a['id'], $old));
    Database::statement("UPDATE encounters SET appointment_id = NULL WHERE appointment_id IN ($ids)");
    Database::statement("DELETE FROM appointments WHERE id IN ($ids)");
    echo '  cleared ' . count($old) . " appointment(s) already on today\n";
}

$day = [
    ['09:00', 'completed',       'Follow-up on blood pressure'],
    ['09:30', 'completed',       'Sore throat, three days'],
    ['10:00', 'in_consultation', 'Annual check-up'],
    ['10:30', 'arrived',         'Persistent cough'],
    ['11:00', 'arrived',         'Rash on both forearms'],
    ['11:30', 'confirmed',       'Review of lab results'],
    ['12:00', 'cancelled',       'Knee pain'],
    ['12:30', 'no_show',         'Repeat prescription'],
];

$cancelledBecause = 'Patient called to say they cannot make it';

$made = 0;
foreach ($day as $i => [$time, $status, $reason]) {
    $patient = $patients[$i % count($patients)];

    Database::statement(
        'INSERT INTO appointments
            (organization_id, patient_id, doctor_id, scheduled_at, duration_minutes,
             type, status, reason, cancelled_reason, created_by, created_at, updated_at)
         VALUES (:org, :pid, :did, :at, 30, :type, :status, :reason, :cancelled,
                 NULL, :now, :now)',
        [
            'org'    => $orgId,
            'pid'    => (int) $patient['id'],
            'did'    => $doctorId,
            'at'     => $at($time),
            'type'   => 'consultation',
            'status' => $status,
            'reason' => $reason,
            // A no-show has no reason to record — nobody said anything.
            'cancelled' => $status === 'cancelled' ? $cancelledBecause : null,
            'now'    => now(),
        ],
    );
    $made++;
}

echo "  {$doctor['name']}: $made appointment(s) for $today\n";
echo "    2 completed · 1 in consultation · 2 waiting · 1 upcoming · 1 cancelled · 1 no-show\n";

/**
 * A little history behind each of them.
 *
 * The consultation screen shows the patient's previous visits so the doctor can
 * check them before diagnosing (§4). On a fresh demo that section is empty for
 * everyone, which makes it look like it does not work rather than like a new
 * patient — so give the people on today's list a couple of finished visits to
 * have looked back on.
 *
 * Only for patients who have none. Somebody with real history keeps it.
 */
$history = [
    [42, 'Sore throat and fever',      'Acute tonsillitis',        'J03.90'],
    [96, 'Routine dental check',       'Dental caries',            'K02.9'],
    [180, 'Persistent headaches',      'Tension-type headache',    'G44.209'],
];

$given = 0;
foreach (array_slice($patients, 0, 4) as $patient) {
    $pid = (int) $patient['id'];

    $has = Database::selectOne(
        'SELECT COUNT(*) AS n FROM encounters
          WHERE organization_id = :org AND patient_id = :pid AND status = :s',
        ['org' => $orgId, 'pid' => $pid, 's' => 'completed'],
    );
    if ((int) ($has['n'] ?? 0) > 0) {
        continue;
    }

    foreach ($history as [$daysAgo, $complaint, $diagnosis, $icd]) {
        $when = (new DateTimeImmutable("-$daysAgo days", $tz))
            ->setTime(10, 0)->setTimezone($utc)->format('Y-m-d H:i:s');

        $no = Database::selectOne(
            'SELECT COALESCE(MAX(CAST(SUBSTRING(encounter_no, 3) AS UNSIGNED)), 0) AS n
               FROM encounters
              WHERE organization_id = :org AND encounter_no REGEXP \'^E-[0-9]+$\'',
            ['org' => $orgId],
        );
        $encounterNo = sprintf('E-%06d', ((int) ($no['n'] ?? 0)) + 1);

        Database::statement(
            'INSERT INTO encounters
                (organization_id, patient_id, doctor_id, encounter_no, type, status,
                 chief_complaint, created_at, completed_at, updated_at)
             VALUES (:org, :pid, :did, :no, :type, :status, :complaint, :at, :at, :at)',
            [
                'org' => $orgId, 'pid' => $pid, 'did' => $doctorId, 'no' => $encounterNo,
                'type' => 'outpatient', 'status' => 'completed',
                'complaint' => $complaint, 'at' => $when,
            ],
        );

        Database::statement(
            'INSERT INTO diagnoses
                (organization_id, encounter_id, patient_id, icd10_code, description, type,
                 created_at, updated_at)
             VALUES (:org, :eid, :pid, :icd, :desc, :type, :at, :at)',
            [
                'org' => $orgId, 'eid' => (int) Database::lastInsertId(), 'pid' => $pid,
                'icd' => $icd, 'desc' => $diagnosis, 'type' => 'primary', 'at' => $when,
            ],
        );
        $given++;
    }
}

if ($given > 0) {
    echo "  $given past visit(s) added so the history section has something to show\n";
}

echo "\nSign in as doctor@clinic.test to see the dashboard.\n";
