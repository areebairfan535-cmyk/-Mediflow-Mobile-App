<?php
declare(strict_types=1);

/**
 * Phase 3 demo data — the service catalogue (§6).
 *
 *   php database/seed_billing.php
 *
 * Idempotent. Prices are seeded in the organization's own currency and dated
 * from today, so InvoiceFactory can resolve them immediately.
 *
 * The catalogue mirrors §6's list: consultation, follow-up, injection, CBC,
 * X-Ray, ultrasound, MRI, dental procedures, surgery and room charges.
 */

if (PHP_SAPI !== 'cli') {
    exit("This script must be run from the command line.\n");
}

require dirname(__DIR__) . '/bootstrap/app.php';

use App\Core\Database;

echo "Seeding billing catalogue\n=========================\n";

$org = Database::selectOne(
    'SELECT o.*, COALESCE(o.currency_code, c.currency_code) AS cur, c.code AS country_code
       FROM organizations o JOIN countries c ON c.id = o.country_id
      WHERE o.slug = \'demo-clinic\'',
);
if ($org === null) {
    exit("Demo clinic not found. Run: php database/seed.php\n");
}

$orgId    = (int) $org['id'];
$currency = (string) $org['cur'];
$today    = gmdate('Y-m-d');

echo "  organization: {$org['name']} ({$org['country_code']}, $currency)\n\n";

/**
 * code, name, department, category, taxable, price, max discount %
 *
 * Prices are realistic PKR figures for a Faisalabad clinic; a different
 * market gets its own service_prices rows rather than different code.
 */
$catalogue = [
    // Consultations — CONSULT-GEN is the code InvoiceService looks for when
    // billing a visit, so it must exist.
    ['CONSULT-GEN',  'General Consultation',        'OPD',         'consultation', 0, 1500, 20],
    ['CONSULT-FU',   'Follow-up Consultation',      'OPD',         'followup',     0,  800, 25],
    ['CONSULT-SPEC', 'Specialist Consultation',     'OPD',         'consultation', 0, 3000, 15],

    // Procedures — dental, per §6's "dental procedure".
    ['DENT-SCALE',   'Scaling & Polishing',         'Dental',      'procedure',    1, 4000, 15],
    ['DENT-FILL',    'Composite Filling',           'Dental',      'procedure',    1, 3500, 15],
    ['DENT-RCT',     'Root Canal Treatment',        'Dental',      'procedure',    1, 15000, 10],
    ['DENT-EXT',     'Tooth Extraction',            'Dental',      'procedure',    1, 2500, 15],
    ['DENT-CROWN',   'Crown (Porcelain)',           'Dental',      'procedure',    1, 22000, 10],
    ['Pulpotomy',    'Pulpotomy',                   'Dental',      'procedure',    1, 6000, 10],

    // Injections
    ['INJ-IM',       'Intramuscular Injection',     'OPD',         'injection',    1,  500,  0],
    ['INJ-IV',       'IV Infusion',                 'OPD',         'injection',    1, 1200,  0],

    // Laboratory — LAB-GEN is the fallback used when a lab order has no
    // specific service attached.
    ['LAB-GEN',      'Laboratory Test',             'Laboratory',  'lab',          1, 1000, 10],
    ['LAB-CBC',      'Complete Blood Count (CBC)',  'Laboratory',  'lab',          1,  900, 10],
    ['LAB-LFT',      'Liver Function Test',         'Laboratory',  'lab',          1, 2200, 10],
    ['LAB-RFT',      'Renal Function Test',         'Laboratory',  'lab',          1, 2000, 10],
    ['LAB-HBA1C',    'HbA1c',                       'Laboratory',  'lab',          1, 2500, 10],

    // Imaging
    ['IMG-XRAY',     'X-Ray (single view)',         'Radiology',   'imaging',      1, 1800, 10],
    ['IMG-OPG',      'OPG (Panoramic Dental X-Ray)','Radiology',   'imaging',      1, 2500, 10],
    ['IMG-USG',      'Ultrasound',                  'Radiology',   'imaging',      1, 3500, 10],
    ['IMG-MRI',      'MRI Scan',                    'Radiology',   'imaging',      1, 28000,  5],

    // Surgery and room charges
    ['SURG-MINOR',   'Minor Surgery',               'Surgery',     'procedure',    1, 25000,  5],
    ['ROOM-PRIV',    'Private Room (per day)',      'Inpatient',   'room',         1, 8000,  10],
    ['ROOM-GEN',     'General Ward (per day)',      'Inpatient',   'room',         1, 3000,  10],
];

$created = 0;
$priced  = 0;

foreach ($catalogue as [$code, $name, $department, $category, $taxable, $price, $maxDiscount]) {
    $service = Database::selectOne(
        'SELECT * FROM services WHERE organization_id = :org AND code = :code',
        ['org' => $orgId, 'code' => $code],
    );

    if ($service === null) {
        Database::statement(
            'INSERT INTO services
                (organization_id, code, name, department, category,
                 is_taxable, is_active, created_at, updated_at)
             VALUES (:org, :code, :name, :dept, :cat, :tax, 1, :now, :now)',
            [
                'org' => $orgId, 'code' => $code, 'name' => $name,
                'dept' => $department, 'cat' => $category, 'tax' => $taxable,
                'now' => now(),
            ],
        );
        $serviceId = Database::lastInsertId();
        $created++;
        printf("  %-14s %-30s %s %s\n", $code, $name, $currency, number_format($price));
    } else {
        $serviceId = (int) $service['id'];
    }

    // Only add a price if none is currently effective, so re-running does not
    // stack duplicate open prices.
    $existing = Database::selectOne(
        'SELECT id FROM service_prices
          WHERE organization_id = :org AND service_id = :sid
            AND effective_from <= :today
            AND (effective_to IS NULL OR effective_to >= :today)',
        ['org' => $orgId, 'sid' => $serviceId, 'today' => $today],
    );

    if ($existing === null) {
        Database::statement(
            'INSERT INTO service_prices
                (organization_id, service_id, country_id, currency_code, price,
                 tax_rate, max_discount_pct, effective_from, created_at, updated_at)
             VALUES (:org, :sid, NULL, :cur, :price, NULL, :disc, :from, :now, :now)',
            [
                'org' => $orgId, 'sid' => $serviceId, 'cur' => $currency,
                'price' => $price, 'disc' => $maxDiscount, 'from' => $today,
                'now' => now(),
            ],
        );
        $priced++;
    }
}

// ---------------------------------------------------------------
$counts = Database::selectOne(
    'SELECT
       (SELECT COUNT(*) FROM services       WHERE organization_id = :org) AS services,
       (SELECT COUNT(*) FROM service_prices WHERE organization_id = :org) AS prices,
       (SELECT COUNT(*) FROM invoices       WHERE organization_id = :org) AS invoices,
       (SELECT COUNT(*) FROM payments       WHERE organization_id = :org) AS payments',
    ['org' => $orgId],
);

echo "\n=========================\n";
printf("  %d services created, %d prices set\n", $created, $priced);
echo "\nCatalogue now holds:\n";
foreach ($counts as $label => $value) {
    printf("  %-10s %s\n", $label, $value);
}

$taxRate = Database::selectOne(
    'SELECT COALESCE(o.tax_rate, c.default_tax_rate) AS rate
       FROM organizations o JOIN countries c ON c.id = o.country_id
      WHERE o.id = :id',
    ['id' => $orgId],
);

printf(
    "\nTax: %s%% (%s rule for %s)\n",
    number_format(((float) $taxRate['rate']) * 100, 2),
    $org['country_code'] === 'GB' ? 'inclusive' : 'exclusive',
    $org['country_code'],
);
echo "\nComplete a consultation, then POST /encounters/{id}/invoice to bill it.\n\n";
