<?php
declare(strict_types=1);

/**
 * Phase 2 demo data.
 *
 *   php database/seed_clinical.php
 *
 * Idempotent. Adds to the Demo Clinic created by seed.php:
 *   - a medication catalogue (so prescribing is select-not-type, §4)
 *   - doctor weekly schedules
 *   - patients with allergies and conditions
 *   - today's appointments in a spread of states
 *
 * Requires seed.php to have run first.
 */

if (PHP_SAPI !== 'cli') {
    exit("This script must be run from the command line.\n");
}

require dirname(__DIR__) . '/bootstrap/app.php';

use App\Core\Database;

echo "Seeding clinical demo data\n==========================\n";

$org = Database::selectOne('SELECT * FROM organizations WHERE slug = \'demo-clinic\'');
if ($org === null) {
    exit("Demo clinic not found. Run: php database/seed.php\n");
}
$orgId = (int) $org['id'];

$doctors = Database::select(
    'SELECT d.*, u.name FROM doctors d JOIN users u ON u.id = d.user_id
      WHERE d.organization_id = :org ORDER BY d.id',
    ['org' => $orgId],
);
if ($doctors === []) {
    exit("No doctors in the demo clinic. Run: php database/seed.php\n");
}

$actor = (int) Database::selectOne(
    'SELECT id FROM users WHERE email = \'owner@clinic.test\''
)['id'];

// ---------------------------------------------------------------
// Medication catalogue (§4: defaults pre-filled so a doctor selects)
// ---------------------------------------------------------------
echo "\n[medications]\n";

$medications = [
    ['Amoxicillin',   'Augmentin',  'tablet',    '625mg', '1 tablet', 'three times a day', '7 days'],
    ['Paracetamol',   'Panadol',    'tablet',    '500mg', '1-2 tablets', 'every 6 hours as needed', '5 days'],
    ['Ibuprofen',     'Brufen',     'tablet',    '400mg', '1 tablet', 'twice a day after meals', '5 days'],
    ['Metronidazole', 'Flagyl',     'tablet',    '400mg', '1 tablet', 'three times a day', '5 days'],
    ['Chlorhexidine', 'Corsodyl',   'mouthwash', '0.2%',  '10ml rinse', 'twice a day', '14 days'],
    ['Omeprazole',    'Risek',      'capsule',   '20mg',  '1 capsule', 'once daily before breakfast', '14 days'],
    ['Cetirizine',    'Zyrtec',     'tablet',    '10mg',  '1 tablet', 'at night', '7 days'],
    ['Diclofenac',    'Voltaren',   'gel',       '1%',    'apply thinly', 'three times a day', '10 days'],
    ['Azithromycin',  'Zithromax',  'tablet',    '500mg', '1 tablet', 'once daily', '3 days'],
    ['Lignocaine',    'Xylocaine',  'injection', '2%',    '1.8ml', 'single dose', 'once'],
];

foreach ($medications as [$name, $brand, $form, $strength, $dosage, $freq, $duration]) {
    $exists = Database::selectOne(
        'SELECT id FROM medications WHERE organization_id = :org AND name = :n',
        ['org' => $orgId, 'n' => $name],
    );
    if ($exists !== null) {
        continue;
    }
    Database::statement(
        'INSERT INTO medications
            (organization_id, name, brand_name, form, strength,
             default_dosage, default_frequency, default_duration,
             is_active, created_at, updated_at)
         VALUES (:org, :n, :b, :f, :s, :dose, :freq, :dur, 1, :now, :now)',
        [
            'org' => $orgId, 'n' => $name, 'b' => $brand, 'f' => $form, 's' => $strength,
            'dose' => $dosage, 'freq' => $freq, 'dur' => $duration, 'now' => now(),
        ],
    );
    echo "  $name ($brand)\n";
}

// ---------------------------------------------------------------
// Doctor schedules — Mon–Sat mornings, plus afternoons for the first
// ---------------------------------------------------------------
echo "\n[schedules]\n";

foreach ($doctors as $i => $doctor) {
    $existing = Database::selectOne(
        'SELECT COUNT(*) AS c FROM doctor_schedules
          WHERE organization_id = :org AND doctor_id = :did',
        ['org' => $orgId, 'did' => (int) $doctor['id']],
    );
    if ((int) $existing['c'] > 0) {
        echo "  {$doctor['name']} — already scheduled\n";
        continue;
    }

    $windows = $i === 0
        ? [['09:00:00', '13:00:00'], ['17:00:00', '20:00:00']]
        : [['10:00:00', '14:00:00']];

    foreach (range(1, 6) as $day) {           // Mon(1) .. Sat(6)
        foreach ($windows as [$start, $end]) {
            Database::statement(
                'INSERT INTO doctor_schedules
                    (organization_id, doctor_id, day_of_week, start_time, end_time,
                     is_active, created_at, updated_at)
                 VALUES (:org, :did, :dow, :s, :e, 1, :now, :now)',
                [
                    'org' => $orgId, 'did' => (int) $doctor['id'], 'dow' => $day,
                    's' => $start, 'e' => $end, 'now' => now(),
                ],
            );
        }
    }
    echo "  {$doctor['name']} — Mon-Sat, " . count($windows) . " window(s)/day\n";
}

// ---------------------------------------------------------------
// Patients
// ---------------------------------------------------------------
echo "\n[patients]\n";

$patients = [
    ['Fatima',  'Noor',    '1994-03-12', 'female', '0300-1234501', 'B+',  'Kotwali Road, Faisalabad'],
    ['Hassan',  'Raza',    '1986-11-02', 'male',   '0300-1234502', 'O+',  'Jaranwala Road, Faisalabad'],
    ['Ayesha',  'Siddiqui','2001-07-25', 'female', '0300-1234503', 'A+',  'Peoples Colony, Faisalabad'],
    ['Usman',   'Tariq',   '1979-01-30', 'male',   '0300-1234504', 'AB+', 'Madina Town, Faisalabad'],
    ['Zainab',  'Iqbal',   '2015-09-14', 'female', '0300-1234505', 'O-',  'Samanabad, Faisalabad'],
    ['Bilal',   'Sheikh',  '1968-05-08', 'male',   '0300-1234506', 'B-',  'Ghulam Muhammad Abad, Faisalabad'],
];

$patientIds = [];

foreach ($patients as $i => [$first, $last, $dob, $gender, $phone, $blood, $address]) {
    // Matched on name + date of birth, NOT on phone. A phone number is
    // exactly the field a demo (or a test suite) edits, and matching on it
    // meant a re-run quietly registered a second copy of the same person —
    // which then ended up sharing the first one's app login.
    $existing = Database::selectOne(
        'SELECT * FROM patients
          WHERE organization_id = :org AND first_name = :first
            AND last_name = :last AND date_of_birth = :dob',
        ['org' => $orgId, 'first' => $first, 'last' => $last, 'dob' => $dob],
    );
    if ($existing !== null) {
        $patientIds[] = (int) $existing['id'];
        echo "  exists  $first $last\n";
        continue;
    }

    $mrnRow = Database::selectOne(
        'SELECT COALESCE(MAX(CAST(SUBSTRING(mrn, 3) AS UNSIGNED)), 0) AS n
           FROM patients WHERE organization_id = :org AND mrn REGEXP \'^P-[0-9]+$\'',
        ['org' => $orgId],
    );
    $mrn = sprintf('P-%06d', ((int) $mrnRow['n']) + 1);

    Database::statement(
        'INSERT INTO patients
            (organization_id, mrn, first_name, last_name, date_of_birth, gender,
             phone, address, city, blood_group, emergency_name, emergency_phone,
             emergency_relation, status, created_by, updated_by, created_at, updated_at)
         VALUES (:org, :mrn, :fn, :ln, :dob, :g, :phone, :addr, \'Faisalabad\', :blood,
                 :en, :ep, :er, \'active\', :by, :by, :now, :now)',
        [
            'org' => $orgId, 'mrn' => $mrn, 'fn' => $first, 'ln' => $last,
            'dob' => $dob, 'g' => $gender, 'phone' => $phone, 'addr' => $address,
            'blood' => $blood,
            'en' => 'Emergency Contact', 'ep' => '0300-9999' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            'er' => $i % 2 === 0 ? 'spouse' : 'parent',
            'by' => $actor, 'now' => now(),
        ],
    );

    $id           = Database::lastInsertId();
    $patientIds[] = $id;
    echo "  created $mrn  $first $last\n";
}

// ---------------------------------------------------------------
// Allergies and conditions — so the prescribing warning has something
// real to fire on
// ---------------------------------------------------------------
echo "\n[clinical history]\n";

$allergies = [
    [0, 'Penicillin',  'Rash and swelling',      'severe'],
    [0, 'Amoxicillin', 'Hives',                  'moderate'],
    [3, 'Ibuprofen',   'Stomach bleeding',       'severe'],
    [4, 'Peanuts',     'Anaphylaxis',            'life_threatening'],
];

foreach ($allergies as [$idx, $substance, $reaction, $severity]) {
    if (!isset($patientIds[$idx])) {
        continue;
    }
    $exists = Database::selectOne(
        'SELECT id FROM allergies WHERE organization_id = :org AND patient_id = :pid AND substance = :s',
        ['org' => $orgId, 'pid' => $patientIds[$idx], 's' => $substance],
    );
    if ($exists !== null) {
        continue;
    }
    Database::statement(
        'INSERT INTO allergies
            (organization_id, patient_id, substance, reaction, severity,
             noted_on, is_active, created_by, created_at, updated_at)
         VALUES (:org, :pid, :s, :r, :sev, :noted, 1, :by, :now, :now)',
        [
            'org' => $orgId, 'pid' => $patientIds[$idx], 's' => $substance,
            'r' => $reaction, 'sev' => $severity, 'noted' => gmdate('Y-m-d'),
            'by' => $actor, 'now' => now(),
        ],
    );
    echo "  allergy   patient #{$patientIds[$idx]} -> $substance ($severity)\n";
}

$conditions = [
    [1, 'Type 2 Diabetes Mellitus', 'E11', 'chronic'],
    [3, 'Hypertension',             'I10', 'chronic'],
    [5, 'Chronic Periodontitis',    'K05.3', 'active'],
];

foreach ($conditions as [$idx, $name, $icd, $status]) {
    if (!isset($patientIds[$idx])) {
        continue;
    }
    $exists = Database::selectOne(
        'SELECT id FROM medical_conditions
          WHERE organization_id = :org AND patient_id = :pid AND name = :n',
        ['org' => $orgId, 'pid' => $patientIds[$idx], 'n' => $name],
    );
    if ($exists !== null) {
        continue;
    }
    Database::statement(
        'INSERT INTO medical_conditions
            (organization_id, patient_id, name, icd10_code, status,
             diagnosed_on, created_by, created_at, updated_at)
         VALUES (:org, :pid, :n, :icd, :status, :dx, :by, :now, :now)',
        [
            'org' => $orgId, 'pid' => $patientIds[$idx], 'n' => $name, 'icd' => $icd,
            'status' => $status, 'dx' => gmdate('Y-m-d', strtotime('-2 years')),
            'by' => $actor, 'now' => now(),
        ],
    );
    echo "  condition patient #{$patientIds[$idx]} -> $name\n";
}

// ---------------------------------------------------------------
// Today's appointments, in a spread of states
// ---------------------------------------------------------------
echo "\n[appointments]\n";

$already = Database::selectOne(
    'SELECT COUNT(*) AS c FROM appointments
      WHERE organization_id = :org AND DATE(scheduled_at) = CURDATE()',
    ['org' => $orgId],
);

if ((int) $already['c'] > 0) {
    echo "  {$already['c']} already booked for today — skipping\n";
} else {
    $primary = (int) $doctors[0]['id'];
    $second  = (int) ($doctors[1]['id'] ?? $doctors[0]['id']);

    // The schedule below is written in CLINIC-LOCAL time, because that is how
    // a receptionist thinks about a day. Rows are stored in UTC, so each is
    // converted through the organization's timezone (§23).
    $settings = Database::selectOne(
        'SELECT COALESCE(o.timezone, c.timezone) AS tz
           FROM organizations o JOIN countries c ON c.id = o.country_id
          WHERE o.id = :id',
        ['id' => $orgId],
    );
    $clinicTz = new DateTimeZone((string) ($settings['tz'] ?? 'UTC'));
    $utcTz    = new DateTimeZone('UTC');
    $today    = (new DateTimeImmutable('now', $clinicTz))->format('Y-m-d');

    $toUtc = static fn(string $time): string =>
        (new DateTimeImmutable("$today $time", $clinicTz))
            ->setTimezone($utcTz)->format('Y-m-d H:i:s');

    // Times sit inside the seeded 09:00-13:00 window.
    $schedule = [
        ['09:00:00', 0, $primary, 'completed', 'Toothache upper left'],
        ['09:30:00', 1, $primary, 'completed', 'Routine check-up'],
        ['10:00:00', 2, $primary, 'arrived',   'Bleeding gums'],
        ['10:30:00', 3, $primary, 'confirmed', 'Follow-up after extraction'],
        ['11:00:00', 4, $primary, 'booked',    'Child dental check'],
        ['11:30:00', 5, $primary, 'booked',    'Sensitivity to cold'],
        ['10:00:00', 1, $second,  'booked',    'Second opinion'],
    ];

    foreach ($schedule as [$time, $patientIdx, $doctorId, $status, $reason]) {
        if (!isset($patientIds[$patientIdx])) {
            continue;
        }
        Database::statement(
            'INSERT INTO appointments
                (organization_id, patient_id, doctor_id, scheduled_at, duration_minutes,
                 type, status, reason, booked_by, created_by, updated_by, created_at, updated_at)
             VALUES (:org, :pid, :did, :at, 30, \'consultation\', :status, :reason,
                     :by, :by, :by, :now, :now)',
            [
                'org' => $orgId, 'pid' => $patientIds[$patientIdx], 'did' => $doctorId,
                'at' => $toUtc($time), 'status' => $status, 'reason' => $reason,
                'by' => $actor, 'now' => now(),
            ],
        );
        echo "  $time clinic time  patient #{$patientIds[$patientIdx]}  [$status]  $reason\n";
    }
}

// ---------------------------------------------------------------
// A patient app login, so Phase 4 has something to sign in with.
// ---------------------------------------------------------------
echo "\n[patient app account]\n";

$appPatientId = $patientIds[0] ?? null;

if ($appPatientId !== null) {
    $linked = Database::selectOne(
        'SELECT user_id FROM patients WHERE id = :id',
        ['id' => $appPatientId],
    );

    if (!empty($linked['user_id'])) {
        echo "  exists  patient #$appPatientId already has an account\n";
    } else {
        $users   = new App\Repositories\UserRepository();
        $email   = 'patient@demo.test';
        $account = $users->firstWhere(['email' => $email]);

        if ($account === null) {
            $account = $users->create([
                'name'       => 'Fatima Noor',
                'email'      => $email,
                'password'   => App\Repositories\UserRepository::hashPassword('Password123'),
                'status'     => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $patientRole = Database::selectOne(
            'SELECT id FROM roles WHERE slug = \'patient\' AND organization_id IS NULL',
        );

        $member = Database::selectOne(
            'SELECT id FROM organization_users WHERE organization_id = :org AND user_id = :uid',
            ['org' => $orgId, 'uid' => (int) $account['id']],
        );

        if ($member === null) {
            Database::statement(
                'INSERT INTO organization_users
                    (organization_id, user_id, role_id, job_title, status,
                     joined_at, created_at, updated_at)
                 VALUES (:org, :uid, :role, \'Patient\', \'active\', :now, :now, :now)',
                [
                    'org' => $orgId, 'uid' => (int) $account['id'],
                    'role' => (int) $patientRole['id'], 'now' => now(),
                ],
            );
        }

        Database::statement(
            'UPDATE patients SET user_id = :uid, updated_at = :now WHERE id = :id',
            ['uid' => (int) $account['id'], 'now' => now(), 'id' => $appPatientId],
        );

        echo "  created patient@demo.test -> patient #$appPatientId (Fatima Noor)\n";
    }
}

// ---------------------------------------------------------------
$counts = Database::selectOne(
    'SELECT
       (SELECT COUNT(*) FROM patients     WHERE organization_id = :org) AS patients,
       (SELECT COUNT(*) FROM medications  WHERE organization_id = :org) AS medications,
       (SELECT COUNT(*) FROM appointments WHERE organization_id = :org) AS appointments,
       (SELECT COUNT(*) FROM doctor_schedules WHERE organization_id = :org) AS schedule_slots',
    ['org' => $orgId],
);

echo "\n==========================\n";
echo "Demo Clinic now has:\n";
foreach ($counts as $label => $value) {
    printf("  %-15s %s\n", $label, $value);
}
echo "\nSign in as doctor@clinic.test or owner@clinic.test (Password123)\n";
echo "and open the Patients / Appointments screens.\n\n";
