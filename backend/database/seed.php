<?php
declare(strict_types=1);

/**
 * Seeder.
 *
 *   php database/seed.php
 *
 * Idempotent — safe to run repeatedly. Creates:
 *   - countries        PK / US / GB / AE (§23)
 *   - permissions      the full slug catalogue, grouped by module
 *   - system roles     10 roles from §10, with their permission grants
 *   - plans            Free / Starter / Professional / Enterprise (§22)
 *   - a platform admin (§21)
 *   - a demo clinic with an owner, a doctor and a receptionist
 */

if (PHP_SAPI !== 'cli') {
    exit("This script must be run from the command line.\n");
}

require dirname(__DIR__) . '/bootstrap/app.php';

use App\Core\Database;
use App\Repositories\UserRepository;

const DEMO_PASSWORD = 'Password123';

echo "Seeding MediFlow\n================\n";

// ---------------------------------------------------------------
// Countries (§23)
// ---------------------------------------------------------------
echo "\n[countries]\n";

$countries = [
    ['PK', 'Pakistan',             'PKR', 'Rs',  'Asia/Karachi',   'd/m/Y', 0.1700, 'INV'],
    ['US', 'United States',        'USD', '$',   'America/New_York', 'm/d/Y', 0.0000, 'INV'],
    ['GB', 'United Kingdom',       'GBP', '£',   'Europe/London',  'd/m/Y', 0.2000, 'INV'],
    ['AE', 'United Arab Emirates', 'AED', 'AED', 'Asia/Dubai',     'd/m/Y', 0.0500, 'INV'],
];

foreach ($countries as [$code, $name, $currency, $symbol, $tz, $dateFormat, $tax, $prefix]) {
    Database::statement(
        'INSERT INTO countries
            (code, name, currency_code, currency_symbol, timezone, date_format,
             default_tax_rate, invoice_prefix, is_active, created_at, updated_at)
         VALUES (:code, :name, :cur, :sym, :tz, :df, :tax, :prefix, 1, :now, :now)
         ON DUPLICATE KEY UPDATE name = VALUES(name), updated_at = VALUES(updated_at)',
        [
            'code' => $code, 'name' => $name, 'cur' => $currency, 'sym' => $symbol,
            'tz' => $tz, 'df' => $dateFormat, 'tax' => $tax, 'prefix' => $prefix,
            'now' => now(),
        ],
    );
    echo "  $code  $name ($currency)\n";
}

// ---------------------------------------------------------------
// Permissions
// ---------------------------------------------------------------
echo "\n[permissions]\n";

/** module => [slug => label] */
$permissionCatalogue = [
    'admin' => [
        'organization.view'   => 'View organization settings',
        'organization.update' => 'Update organization settings',
        'member.view'         => 'View team members',
        'member.create'       => 'Add team members',
        'member.update'       => 'Change member roles and status',
        'member.delete'       => 'Remove team members',
        'role.manage'         => 'Create and edit custom roles',
        'audit.view'          => 'View audit logs',
        'subscription.manage' => 'Manage plan and billing',
    ],
    'clinical' => [
        'patient.view'       => 'View patients',
        'patient.create'     => 'Register patients',
        'patient.update'     => 'Update patient details',
        'patient.delete'     => 'Deactivate patients',
        'appointment.view'   => 'View appointments',
        'appointment.create' => 'Book appointments',
        'appointment.update' => 'Reschedule or change appointments',
        'appointment.cancel' => 'Cancel appointments',
        'encounter.view'     => 'View consultations',
        'encounter.create'   => 'Start a consultation',
        'encounter.update'   => 'Edit a consultation',
        'diagnosis.manage'   => 'Record diagnoses',
        'prescription.view'  => 'View prescriptions',
        'prescription.create' => 'Issue prescriptions',
        'lab.view'           => 'View lab orders and results',
        'lab.create'         => 'Order lab tests',
        'lab.result'         => 'Enter lab results',
        'procedure.manage'   => 'Record procedures',
        'document.view'      => 'View medical documents',
        'document.upload'    => 'Upload medical documents',
        'schedule.manage'    => 'Manage doctor availability',
    ],
    'billing' => [
        'service.view'    => 'View service catalogue',
        'service.manage'  => 'Manage services and prices',
        'invoice.view'    => 'View invoices',
        'invoice.create'  => 'Create invoices',
        'invoice.update'  => 'Edit draft invoices',
        'invoice.issue'   => 'Issue invoices',
        'invoice.cancel'  => 'Cancel invoices',
        'payment.view'    => 'View payments',
        'payment.create'  => 'Record payments',
        'refund.create'   => 'Request refunds',
        'refund.approve'  => 'Approve refunds',
        'report.view'     => 'View financial reports',
    ],
    'insurance' => [
        'policy.view'     => 'View insurance policies',
        'policy.manage'   => 'Manage insurance policies',
        'claim.view'      => 'View claims',
        'claim.create'    => 'Create claims',
        'claim.submit'    => 'Submit claims to insurers',
        'claim.update'    => 'Update claim status',
    ],
    'ai' => [
        'ai.suggest_billing' => 'Use the AI billing assistant',
        'ai.draft_note'      => 'Use the AI documentation assistant',
        'ai.check_claim'     => 'Use the AI claim assistant',
    ],
];

$permissionCount = 0;
foreach ($permissionCatalogue as $module => $slugs) {
    foreach ($slugs as $slug => $label) {
        Database::statement(
            'INSERT INTO permissions (slug, name, module, created_at)
             VALUES (:slug, :name, :module, :now)
             ON DUPLICATE KEY UPDATE name = VALUES(name), module = VALUES(module)',
            ['slug' => $slug, 'name' => $label, 'module' => $module, 'now' => now()],
        );
        $permissionCount++;
    }
}
echo "  $permissionCount permissions across " . count($permissionCatalogue) . " modules\n";

$allSlugs = array_merge(...array_map('array_keys', array_values($permissionCatalogue)));

// ---------------------------------------------------------------
// System roles (§10) and their grants
// ---------------------------------------------------------------
echo "\n[roles]\n";

$clinicalReadOnly = [
    'patient.view', 'appointment.view', 'encounter.view',
    'prescription.view', 'lab.view', 'document.view',
];

$roles = [
    'org_owner' => [
        'name'        => 'Organization Owner',
        'description' => 'Full control of the clinic, including billing and team.',
        // Everything, AI included. Whether the AI assistants may be used at
        // all is a SUBSCRIPTION question (§22 caps `max_ai_calls_month` per
        // plan), not a role question — withholding the slug from the person
        // who pays for the plan just makes the feature unreachable.
        'permissions' => $allSlugs,
    ],
    'org_admin' => [
        'name'        => 'Administrator',
        'description' => 'Runs the clinic day to day; no subscription control.',
        'permissions' => array_values(array_diff(
            $allSlugs,
            ['subscription.manage', 'role.manage'],
        )),
    ],
    'doctor' => [
        'name'        => 'Doctor',
        'description' => 'Consults patients, prescribes, orders labs, records procedures.',
        'permissions' => [
            'patient.view', 'patient.create', 'patient.update',
            'appointment.view', 'appointment.create', 'appointment.update', 'appointment.cancel',
            'encounter.view', 'encounter.create', 'encounter.update',
            'diagnosis.manage',
            'prescription.view', 'prescription.create',
            'lab.view', 'lab.create',
            'procedure.manage',
            'document.view', 'document.upload',
            'schedule.manage',
            'service.view', 'invoice.view', 'invoice.create',
            'policy.view', 'claim.view',
            'ai.draft_note', 'ai.suggest_billing',
        ],
    ],
    'nurse' => [
        'name'        => 'Nurse',
        'description' => 'Assists with patients, vitals and lab logistics.',
        'permissions' => [
            ...$clinicalReadOnly,
            'patient.create', 'patient.update',
            'appointment.view', 'appointment.update',
            'encounter.update',
            'lab.create', 'document.upload',
        ],
    ],
    'receptionist' => [
        'name'        => 'Receptionist',
        'description' => 'Front desk: registration, appointments, taking payments.',
        'permissions' => [
            'patient.view', 'patient.create', 'patient.update',
            'appointment.view', 'appointment.create', 'appointment.update', 'appointment.cancel',
            'service.view',
            'invoice.view', 'invoice.create', 'invoice.issue',
            'payment.view', 'payment.create',
            'document.view',
            'policy.view',
        ],
    ],
    'accountant' => [
        'name'        => 'Accountant',
        'description' => 'Financial oversight; no clinical record access.',
        'permissions' => [
            'service.view', 'service.manage',
            'invoice.view', 'invoice.cancel',
            'payment.view', 'refund.approve',
            'report.view', 'audit.view',
        ],
    ],
    'billing_staff' => [
        'name'        => 'Billing Staff',
        'description' => 'Invoices, claims and the revenue cycle.',
        'permissions' => [
            'patient.view',
            'encounter.view', 'procedure.manage',
            'service.view',
            'invoice.view', 'invoice.create', 'invoice.update', 'invoice.issue',
            'payment.view', 'payment.create', 'refund.create',
            'report.view',
            'policy.view', 'policy.manage',
            'claim.view', 'claim.create', 'claim.submit', 'claim.update',
            'ai.suggest_billing', 'ai.check_claim',
        ],
    ],
    'lab_staff' => [
        'name'        => 'Lab Staff',
        'description' => 'Processes lab orders and enters results.',
        'permissions' => [
            'patient.view',
            'lab.view', 'lab.result',
            'document.view', 'document.upload',
        ],
    ],
    'pharmacist' => [
        'name'        => 'Pharmacist',
        'description' => 'Dispenses against prescriptions.',
        'permissions' => ['patient.view', 'prescription.view', 'document.view'],
    ],
    'patient' => [
        'name'        => 'Patient',
        'description' => 'Mobile app access to their own record only. Holds NO '
                       . 'clinic permissions by design — see below.',
        // Deliberately EMPTY.
        //
        // The patient portal (/api/v1/patient/*) is scoped by IDENTITY: every
        // route resolves the record from patients.user_id and no route takes a
        // patient id. Permissions are the wrong tool for that job.
        //
        // Granting the obvious-looking slugs was an actual hole, caught by the
        // Phase 4 suite: `invoice.view` also opens GET /invoices, the
        // clinic-wide list, and `appointment.cancel` also opens
        // PUT /appointments/{id}/status for ANY appointment in the clinic.
        // A permission that means "my own X" and one that means "everyone's X"
        // cannot share a slug.
        'permissions' => [],
    ],
];

foreach ($roles as $slug => $definition) {
    Database::statement(
        'INSERT INTO roles (organization_id, slug, name, description, is_system, created_at, updated_at)
         VALUES (NULL, :slug, :name, :desc, 1, :now, :now)
         ON DUPLICATE KEY UPDATE
             name = VALUES(name), description = VALUES(description), updated_at = VALUES(updated_at)',
        [
            'slug' => $slug,
            'name' => $definition['name'],
            'desc' => $definition['description'],
            'now'  => now(),
        ],
    );

    $role = Database::selectOne(
        'SELECT id FROM roles WHERE slug = :slug AND organization_id IS NULL',
        ['slug' => $slug],
    );
    $roleId = (int) $role['id'];

    (new App\Repositories\RoleRepository())->syncPermissions($roleId, $definition['permissions']);

    // Report what the role actually HOLDS, not what the list above asked for.
    // syncPermissions drops slugs that no permission row matches, so a typo
    // would otherwise be invisible here — and a role quietly missing one
    // permission is the kind of bug that surfaces as "it works for me".
    $wanted  = array_unique($definition['permissions']);
    $granted = (int) (Database::selectOne(
        'SELECT COUNT(*) AS c FROM role_permissions WHERE role_id = :role',
        ['role' => $roleId],
    )['c'] ?? 0);

    printf("  %-14s %2d permissions\n", $slug, $granted);

    if ($granted !== count($wanted)) {
        $have = array_column(Database::select(
            'SELECT p.slug FROM role_permissions rp
               JOIN permissions p ON p.id = rp.permission_id
              WHERE rp.role_id = :role',
            ['role' => $roleId],
        ), 'slug');

        printf(
            "          ! unknown slug(s) ignored: %s\n",
            implode(', ', array_diff($wanted, $have)),
        );
    }
}

// ---------------------------------------------------------------
// Plans (§22)
// ---------------------------------------------------------------
echo "\n[plans]\n";

$plans = [
    ['free',         'Free',         0,     0,      2,   3,   200,   500,   50,  200,   0, ['insurance' => false, 'ai_billing' => false]],
    ['starter',      'Starter',      29,    290,    5,  10,  2000,  5000,  500, 2000,  100, ['insurance' => false, 'ai_billing' => false]],
    ['professional', 'Professional', 99,    990,   20,  50, 20000, 25000, 5000, 20000, 1000, ['insurance' => true,  'ai_billing' => true]],
    ['enterprise',   'Enterprise',   299,  2990, null, null,  null,  null, null,  null, null, ['insurance' => true,  'ai_billing' => true]],
];

foreach ($plans as $i => $p) {
    [$slug, $name, $monthly, $yearly, $doctors, $staff, $patients,
     $storage, $invoices, $appointments, $ai, $features] = $p;

    Database::statement(
        'INSERT INTO plans
            (slug, name, price_monthly, price_yearly, currency_code,
             max_doctors, max_staff, max_patients, max_storage_mb,
             max_invoices_month, max_appointments_month, max_ai_calls_month,
             features, is_active, sort_order, created_at, updated_at)
         VALUES (:slug, :name, :monthly, :yearly, \'USD\',
                 :doctors, :staff, :patients, :storage,
                 :invoices, :appointments, :ai,
                 :features, 1, :sort, :now, :now)
         ON DUPLICATE KEY UPDATE
             name = VALUES(name), price_monthly = VALUES(price_monthly),
             features = VALUES(features), updated_at = VALUES(updated_at)',
        [
            'slug' => $slug, 'name' => $name, 'monthly' => $monthly, 'yearly' => $yearly,
            'doctors' => $doctors, 'staff' => $staff, 'patients' => $patients,
            'storage' => $storage, 'invoices' => $invoices,
            'appointments' => $appointments, 'ai' => $ai,
            'features' => json_encode($features), 'sort' => $i, 'now' => now(),
        ],
    );
    printf("  %-13s \$%s/mo\n", $slug, $monthly);
}

// ---------------------------------------------------------------
// Users
// ---------------------------------------------------------------
echo "\n[users]\n";

$users = new UserRepository();

/** Create a user if the email is free; return the row either way. */
$upsertUser = static function (
    UserRepository $repo,
    string $name,
    string $email,
    string $role = 'user',
    bool $platformAdmin = false,
): array {
    $existing = $repo->firstWhere(['email' => $email]);
    if ($existing !== null) {
        echo "  exists  $email\n";
        return $existing;
    }
    $created = $repo->create([
        'name'              => $name,
        'email'             => $email,
        'password'          => UserRepository::hashPassword(DEMO_PASSWORD),
        'is_platform_admin' => $platformAdmin ? 1 : 0,
        'status'            => 'active',
        'email_verified_at' => now(),
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);
    echo "  created $email  ($role)\n";
    return $created;
};

$admin        = $upsertUser($users, 'Platform Admin', 'admin@mediflow.test',  'platform admin', true);
$owner        = $upsertUser($users, 'Dr. Ayesha Khan', 'owner@clinic.test',   'clinic owner');
$doctor       = $upsertUser($users, 'Dr. Bilal Ahmed', 'doctor@clinic.test',  'doctor');
$receptionist = $upsertUser($users, 'Sana Malik',      'reception@clinic.test', 'receptionist');
// Billing staff exist as their own account so the separation of duties in §7
// is demonstrable: they may REQUEST a refund but not approve their own.
$billing      = $upsertUser($users, 'Imran Yousaf',    'billing@clinic.test', 'billing staff');

// ---------------------------------------------------------------
// Demo organization
// ---------------------------------------------------------------
echo "\n[organization]\n";

$pk  = Database::selectOne('SELECT id FROM countries WHERE code = \'PK\'');
$org = Database::selectOne('SELECT * FROM organizations WHERE slug = \'demo-clinic\'');

if ($org === null) {
    Database::statement(
        'INSERT INTO organizations
            (name, slug, country_id, email, phone, address, city, status, created_at, updated_at)
         VALUES (:name, :slug, :country, :email, :phone, :address, :city, \'active\', :now, :now)',
        [
            'name'    => 'Demo Dental & Medical Clinic',
            'slug'    => 'demo-clinic',
            'country' => (int) $pk['id'],
            'email'   => 'hello@democlinic.test',
            'phone'   => '+92 41 1234567',
            'address' => 'Kotwali Road',
            'city'    => 'Faisalabad',
            'now'     => now(),
        ],
    );
    $org = Database::selectOne('SELECT * FROM organizations WHERE slug = \'demo-clinic\'');
    echo "  created Demo Dental & Medical Clinic (Faisalabad, PK)\n";
} else {
    echo "  exists  demo-clinic\n";
}

$orgId = (int) $org['id'];

// Memberships
$roleId = static function (string $slug): int {
    $row = Database::selectOne(
        'SELECT id FROM roles WHERE slug = :slug AND organization_id IS NULL',
        ['slug' => $slug],
    );
    return (int) $row['id'];
};

$addMember = static function (int $orgId, array $user, string $roleSlug, string $title) use ($roleId): void {
    $existing = Database::selectOne(
        'SELECT id FROM organization_users WHERE organization_id = :org AND user_id = :uid',
        ['org' => $orgId, 'uid' => (int) $user['id']],
    );
    if ($existing !== null) {
        echo "  exists  {$user['email']} in org\n";
        return;
    }
    Database::statement(
        'INSERT INTO organization_users
            (organization_id, user_id, role_id, job_title, status, joined_at, created_at, updated_at)
         VALUES (:org, :uid, :role, :title, \'active\', :now, :now, :now)',
        [
            'org' => $orgId, 'uid' => (int) $user['id'], 'role' => $roleId($roleSlug),
            'title' => $title, 'now' => now(),
        ],
    );
    echo "  added   {$user['email']} as $roleSlug\n";
};

$addMember($orgId, $owner,        'org_owner',     'Owner / Principal Dentist');
$addMember($orgId, $doctor,       'doctor',        'Consultant');
$addMember($orgId, $receptionist, 'receptionist',  'Front Desk');
$addMember($orgId, $billing,      'billing_staff', 'Billing & Claims');

// Doctor rows for the two clinicians
foreach ([[$owner, 'Endodontist', 'BDS, MDS', 10, 2500], [$doctor, 'General Dentist', 'BDS', 6, 1500]] as
         [$user, $specialty, $qualification, $years, $fee]) {
    $exists = Database::selectOne(
        'SELECT id FROM doctors WHERE organization_id = :org AND user_id = :uid',
        ['org' => $orgId, 'uid' => (int) $user['id']],
    );
    if ($exists !== null) {
        continue;
    }
    Database::statement(
        'INSERT INTO doctors
            (organization_id, user_id, specialty, qualification, experience_years,
             consultation_fee, slot_minutes, is_accepting, created_at, updated_at)
         VALUES (:org, :uid, :spec, :qual, :years, :fee, 15, 1, :now, :now)',
        [
            'org' => $orgId, 'uid' => (int) $user['id'], 'spec' => $specialty,
            'qual' => $qualification, 'years' => $years, 'fee' => $fee, 'now' => now(),
        ],
    );
    echo "  doctor  {$user['name']} — $specialty\n";
}

// A subscription, so the org has a usage envelope from day one (§22).
//
// Professional, not Free: the demo clinic runs claims and the AI assistants,
// and the Free plan allows neither (0 AI calls, insurance off). A demo that
// hits a plan limit on its own seed data teaches the wrong lesson — the
// refusal is worth seeing on a *new* organization, which starts on Free.
$plan = Database::selectOne('SELECT * FROM plans WHERE slug = \'professional\'')
     ?? Database::selectOne('SELECT * FROM plans ORDER BY sort_order LIMIT 1');

$hasSubscription = Database::selectOne(
    'SELECT id FROM subscriptions WHERE organization_id = :org',
    ['org' => $orgId],
);

if ($hasSubscription === null) {
    Database::statement(
        'INSERT INTO subscriptions
            (organization_id, plan_id, status, billing_cycle, currency_code, amount,
             current_period_start, current_period_end, created_at, updated_at)
         VALUES (:org, :plan, \'active\', \'monthly\', \'PKR\', :amount,
                 :start, :end, :now, :now)',
        [
            'org'    => $orgId,
            'plan'   => (int) $plan['id'],
            'amount' => $plan['price_monthly'],
            'start'  => gmdate('Y-m-01'),
            'end'    => gmdate('Y-m-t'),
            'now'    => now(),
        ],
    );
    echo "  plan    {$plan['name']} subscription activated\n";
} else {
    // Re-running the seeder also rolls the period forward, so a database left
    // over from last month does not read as "period ended" to every metered
    // limit check.
    Database::statement(
        'UPDATE subscriptions
            SET plan_id = :plan, status = \'active\', amount = :amount,
                current_period_start = :start, current_period_end = :end, updated_at = :now
          WHERE id = :id',
        [
            'plan'   => (int) $plan['id'],
            'amount' => $plan['price_monthly'],
            'start'  => gmdate('Y-m-01'),
            'end'    => gmdate('Y-m-t'),
            'now'    => now(),
            'id'     => (int) $hasSubscription['id'],
        ],
    );
    echo "  plan    {$plan['name']} subscription refreshed\n";
}

// ---------------------------------------------------------------
$password = DEMO_PASSWORD;

echo <<<TXT

================================================================
Seed complete.

Sign in with any of these (password for all: $password)

  admin@mediflow.test       platform admin  (super admin panel)
  owner@clinic.test         org_owner       (Demo Clinic)
  doctor@clinic.test        doctor          (Demo Clinic)
  reception@clinic.test     receptionist    (Demo Clinic)
  billing@clinic.test       billing_staff   (Demo Clinic)

Organization id: $orgId  — send it as the X-Organization-Id header.
================================================================

TXT;
