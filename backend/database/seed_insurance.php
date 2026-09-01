<?php
declare(strict_types=1);

/**
 * Phase 5 demo data — insurers and patient policies (§8).
 *
 *   php database/seed_insurance.php
 *
 * Idempotent. The policies are deliberately different from each other so the
 * coverage rules in EligibilityService have something to actually exercise:
 * one with a copay, one with a deductible, one nearly exhausted, one expired.
 */

if (PHP_SAPI !== 'cli') {
    exit("This script must be run from the command line.\n");
}

require dirname(__DIR__) . '/bootstrap/app.php';

use App\Core\Database;

echo "Seeding insurance\n=================\n";

$org = Database::selectOne(
    'SELECT o.*, COALESCE(o.currency_code, c.currency_code) AS cur, c.id AS cid
       FROM organizations o JOIN countries c ON c.id = o.country_id
      WHERE o.slug = \'demo-clinic\'',
);
if ($org === null) {
    exit("Demo clinic not found. Run: php database/seed.php\n");
}

$orgId    = (int) $org['id'];
$currency = (string) $org['cur'];

// ---------------------------------------------------------------
// Providers — shared (organization_id NULL), as national insurers would be.
// ---------------------------------------------------------------
echo "\n[providers]\n";

$providers = [
    ['State Life Health',    'SLH',  'claims@statelife.test',  14, 'manual'],
    ['Jubilee Health',       'JBH',  'claims@jubilee.test',    21, 'manual'],
    ['EFU Health Insurance', 'EFU',  'claims@efuhealth.test',  30, 'manual'],
    ['Adamjee Health',       'ADH',  'claims@adamjee.test',    18, 'manual'],
];

$providerIds = [];

foreach ($providers as [$name, $code, $email, $days, $format]) {
    $existing = Database::selectOne(
        'SELECT id FROM insurance_providers WHERE code = :code AND organization_id IS NULL',
        ['code' => $code],
    );

    if ($existing !== null) {
        $providerIds[$code] = (int) $existing['id'];
        echo "  exists  $name\n";
        continue;
    }

    Database::statement(
        'INSERT INTO insurance_providers
            (organization_id, country_id, name, code, contact_email,
             claim_format, avg_settle_days, is_active, created_at, updated_at)
         VALUES (NULL, :cid, :name, :code, :email, :format, :days, 1, :now, :now)',
        [
            'cid' => (int) $org['cid'], 'name' => $name, 'code' => $code,
            'email' => $email, 'format' => $format, 'days' => $days, 'now' => now(),
        ],
    );

    $providerIds[$code] = Database::lastInsertId();
    printf("  %-22s settles in ~%d days\n", $name, $days);
}

// ---------------------------------------------------------------
// Policies
// ---------------------------------------------------------------
echo "\n[policies]\n";

$patients = Database::select(
    'SELECT id, first_name, last_name FROM patients
      WHERE organization_id = :org ORDER BY id LIMIT 6',
    ['org' => $orgId],
);

if ($patients === []) {
    exit("No patients. Run: php database/seed_clinical.php\n");
}

/**
 * patient index, provider, coverage ceiling, copay %, deductible,
 * valid_to offset, description — each row exercises a different branch.
 */
$policies = [
    [0, 'SLH', 500000, 20,     0, '+1 year',  'standard 20% copay'],
    [1, 'JBH', 300000,  0,  5000, '+1 year',  'no copay, PKR 5,000 deductible'],
    [2, 'EFU',  50000, 10,     0, '+6 month', 'small ceiling — caps large claims'],
    [3, 'ADH', 250000, 25,  2000, '-1 month', 'EXPIRED, so eligibility must refuse it'],
];

foreach ($policies as [$idx, $code, $ceiling, $copay, $deductible, $validTo, $note]) {
    if (!isset($patients[$idx], $providerIds[$code])) {
        continue;
    }

    $patient   = $patients[$idx];
    $patientId = (int) $patient['id'];

    $existing = Database::selectOne(
        'SELECT id FROM insurance_policies
          WHERE organization_id = :org AND patient_id = :pid',
        ['org' => $orgId, 'pid' => $patientId],
    );

    $to     = (new DateTimeImmutable('now'))->modify($validTo)->format('Y-m-d');
    $from   = (new DateTimeImmutable('now'))->modify('-6 month')->format('Y-m-d');
    $status = $to < gmdate('Y-m-d') ? 'expired' : 'active';

    // Re-running the seeder restores the demo baseline instead of leaving
    // whatever the last claim run consumed. `coverage_used` decides both the
    // remaining ceiling and — per EligibilityService — whether the deductible
    // is still ahead of the patient, so a carried-over value quietly changes
    // every eligibility answer computed after it.
    if ($existing !== null) {
        Database::statement(
            'UPDATE insurance_policies
                SET coverage_amount = :ceiling, coverage_used = 0,
                    copay_percent = :copay, deductible = :deductible,
                    valid_from = :from, valid_to = :to, status = :status,
                    updated_at = :now
              WHERE id = :id',
            [
                'ceiling'    => $ceiling,
                'copay'      => $copay,
                'deductible' => $deductible,
                'from'       => $from,
                'to'         => $to,
                'status'     => $status,
                'now'        => now(),
                'id'         => (int) $existing['id'],
            ],
        );

        echo "  reset   {$patient['first_name']} {$patient['last_name']}  (cover restored)\n";
        continue;
    }

    Database::statement(
        'INSERT INTO insurance_policies
            (organization_id, patient_id, insurance_provider_id, policy_number,
             member_id, policy_holder_name, relation_to_patient, coverage_type,
             coverage_amount, coverage_used, copay_percent, deductible,
             valid_from, valid_to, is_primary, status, created_at, updated_at)
         VALUES (:org, :pid, :prov, :policy, :member, :holder, \'self\', :type,
                 :ceiling, 0, :copay, :deductible, :from, :to, 1, :status, :now, :now)',
        [
            'org'        => $orgId,
            'pid'        => $patientId,
            'prov'       => $providerIds[$code],
            'policy'     => sprintf('%s-%06d', $code, 100000 + $patientId),
            'member'     => sprintf('M%08d', 5000000 + $patientId),
            'holder'     => $patient['first_name'] . ' ' . $patient['last_name'],
            'type'       => 'Individual health',
            'ceiling'    => $ceiling,
            'copay'      => $copay,
            'deductible' => $deductible,
            'from'       => $from,
            'to'         => $to,
            'status'     => $status,
            'now'        => now(),
        ],
    );

    printf(
        "  %-18s %s  ceiling %s %s  copay %d%%  %s\n",
        $patient['first_name'] . ' ' . $patient['last_name'],
        $code,
        $currency,
        number_format($ceiling),
        $copay,
        $note,
    );
}

// ---------------------------------------------------------------
$counts = Database::selectOne(
    'SELECT
       (SELECT COUNT(*) FROM insurance_providers
         WHERE organization_id = :org OR organization_id IS NULL) AS providers,
       (SELECT COUNT(*) FROM insurance_policies WHERE organization_id = :org) AS policies,
       (SELECT COUNT(*) FROM claims             WHERE organization_id = :org) AS claims',
    ['org' => $orgId],
);

echo "\n=================\n";
foreach ($counts as $label => $value) {
    printf("  %-10s %s\n", $label, $value);
}

echo <<<TXT

Try it:
  GET  /api/v1/invoices/{id}/eligibility   what the insurer would cover
  POST /api/v1/claims                      raise a claim from an issued invoice
  POST /api/v1/claims/{id}/submit          reserves the coverage
  POST /api/v1/claims/{id}/decision        record what the insurer said
  POST /api/v1/claims/{id}/paid            settles the invoice via the ledger


TXT;
