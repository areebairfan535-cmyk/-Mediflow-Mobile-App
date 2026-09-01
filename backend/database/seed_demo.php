<?php
declare(strict_types=1);

/**
 * The demo patient's story.
 *
 *   php database/seed_demo.php
 *
 * Idempotent. Runs last, and exists for one reason: on a fresh install the
 * earlier seeders leave the patient app with appointments but nothing under
 * Records or Bills, so the first thing anyone sees is two empty screens.
 *
 * It gives patient@demo.test a finished visit and the paperwork that follows
 * one — a diagnosis, a prescription, an invoice part-paid and an older one
 * settled in full — written the way the API writes them, so the totals, the
 * balances and the document numbers are the same as if the visit had been put
 * through the clinic app by hand.
 *
 * Requires seed.php, seed_clinical.php and seed_billing.php to have run first.
 */

if (PHP_SAPI !== 'cli') {
    exit("This script must be run from the command line.\n");
}

require dirname(__DIR__) . '/bootstrap/app.php';

use App\Core\Database;

echo "Seeding the demo patient's history\n==================================\n";

$org = Database::selectOne('SELECT * FROM organizations WHERE slug = \'demo-clinic\'');
if ($org === null) {
    exit("Demo clinic not found. Run: php database/seed.php\n");
}
$orgId    = (int) $org['id'];
$currency = 'PKR';

// The patient behind the app account. Everything below hangs off this one row.
$patient = Database::selectOne(
    'SELECT p.* FROM patients p
       JOIN users u ON u.id = p.user_id
      WHERE p.organization_id = :org AND u.email = \'patient@demo.test\'',
    ['org' => $orgId],
);
if ($patient === null) {
    exit("No app account for a patient yet. Run: php database/seed_clinical.php\n");
}
$patientId = (int) $patient['id'];

$doctor = Database::selectOne(
    'SELECT d.*, u.name FROM doctors d JOIN users u ON u.id = d.user_id
      WHERE d.organization_id = :org ORDER BY d.id LIMIT 1',
    ['org' => $orgId],
);
$doctorId = (int) $doctor['id'];

$actor = (int) Database::selectOne(
    'SELECT id FROM users WHERE email = \'owner@clinic.test\''
)['id'];

echo "  patient   {$patient['first_name']} {$patient['last_name']} ({$patient['mrn']})\n";
echo "  doctor    {$doctor['name']}\n";

// ---------------------------------------------------------------
// Helpers that mirror how the app numbers its documents
// ---------------------------------------------------------------

/** Encounters and prescriptions number themselves from the highest so far. */
$nextNo = static function (string $table, string $column, string $prefix, int $orgId): string {
    $offset = strlen($prefix) + 1;
    $row = Database::selectOne(
        "SELECT COALESCE(MAX(CAST(SUBSTRING($column, $offset) AS UNSIGNED)), 0) AS n
           FROM $table
          WHERE organization_id = :org AND $column REGEXP '^$prefix-[0-9]+$'",
        ['org' => $orgId],
    );

    return sprintf('%s-%06d', $prefix, ((int) ($row['n'] ?? 0)) + 1);
};

/**
 * Invoices come off the organization's own counter, not off MAX(). Taking the
 * number any other way would hand the next real invoice a number this one has
 * already used.
 */
$nextInvoiceNo = static function (int $orgId): string {
    return Database::transaction(static function () use ($orgId): string {
        Database::statement(
            'UPDATE organizations SET next_invoice_no = next_invoice_no + 1 WHERE id = :id',
            ['id' => $orgId],
        );
        $row = Database::selectOne(
            'SELECT next_invoice_no FROM organizations WHERE id = :id',
            ['id' => $orgId],
        );

        return sprintf('INV-%06d', (int) ($row['next_invoice_no'] ?? 2) - 1);
    });
};

$service = static function (string $code, int $orgId): ?array {
    return Database::selectOne(
        'SELECT * FROM services WHERE organization_id = :org AND code = :code',
        ['org' => $orgId, 'code' => $code],
    );
};

$priceOf = static function (int $serviceId): string {
    $row = Database::selectOne(
        'SELECT price FROM service_prices
          WHERE service_id = :id AND effective_to IS NULL
       ORDER BY effective_from DESC LIMIT 1',
        ['id' => $serviceId],
    );

    return (string) ($row['price'] ?? '0.00');
};

// ---------------------------------------------------------------
// The visit
// ---------------------------------------------------------------
echo "\n[visit]\n";

$visitDate = gmdate('Y-m-d H:i:s', strtotime('-12 days 10:30'));

$encounter = Database::selectOne(
    'SELECT * FROM encounters
      WHERE organization_id = :org AND patient_id = :pid AND status = \'completed\'
   ORDER BY id LIMIT 1',
    ['org' => $orgId, 'pid' => $patientId],
);

if ($encounter === null) {
    Database::statement(
        'INSERT INTO encounters
            (organization_id, patient_id, doctor_id, encounter_no, type, status,
             chief_complaint, symptoms, examination,
             bp_systolic, bp_diastolic, pulse, temperature_c, weight_kg, height_cm,
             followup_on, started_at, completed_at, created_by, updated_by,
             created_at, updated_at)
         VALUES (:org, :pid, :did, :no, \'outpatient\', \'completed\',
                 :complaint, :symptoms, :exam,
                 118, 76, 74, 36.8, 58.5, 162,
                 :followup, :started, :completed, :by, :by, :now, :now)',
        [
            'org' => $orgId, 'pid' => $patientId, 'did' => $doctorId,
            'no'  => $nextNo('encounters', 'encounter_no', 'E', $orgId),
            'complaint' => 'Bleeding gums and sensitivity on the lower left side',
            'symptoms'  => 'Bleeding on brushing for about three weeks. Cold water hurts. No swelling, no fever.',
            'exam'      => 'Generalised gingival inflammation, heaviest around 36 and 37. '
                         . 'Calculus on the lingual surfaces of the lower anteriors. '
                         . 'No mobility, no pocketing beyond 4mm. Percussion negative.',
            'followup'  => gmdate('Y-m-d', strtotime('+18 days')),
            'started'   => $visitDate,
            'completed' => gmdate('Y-m-d H:i:s', strtotime($visitDate . ' +35 minutes')),
            'by' => $actor, 'now' => now(),
        ],
    );
    $encounter = Database::selectOne(
        'SELECT * FROM encounters WHERE organization_id = :org AND patient_id = :pid
      ORDER BY id DESC LIMIT 1',
        ['org' => $orgId, 'pid' => $patientId],
    );
    echo "  created   {$encounter['encounter_no']} — outpatient visit, completed\n";
} else {
    echo "  exists    {$encounter['encounter_no']}\n";
}
$encounterId = (int) $encounter['id'];

// The diagnosis it reached
$hasDx = Database::selectOne(
    'SELECT id FROM diagnoses WHERE organization_id = :org AND encounter_id = :enc',
    ['org' => $orgId, 'enc' => $encounterId],
);

if ($hasDx === null) {
    Database::statement(
        'INSERT INTO diagnoses
            (organization_id, encounter_id, patient_id, icd10_code, description,
             type, notes, created_by, created_at, updated_at)
         VALUES (:org, :enc, :pid, \'K05.1\', \'Chronic gingivitis\',
                 \'primary\', :notes, :by, :now, :now)',
        [
            'org' => $orgId, 'enc' => $encounterId, 'pid' => $patientId,
            'notes' => 'Plaque-induced. Scaling done today; review in three weeks.',
            'by' => $actor, 'now' => now(),
        ],
    );
    echo "  diagnosis K05.1 Chronic gingivitis\n";
}

// ---------------------------------------------------------------
// The prescription
//
// Nothing here is a penicillin: this patient is seeded with a severe
// penicillin allergy, and a demo whose own sample data ignores its own
// allergy warning is worse than no sample data.
// ---------------------------------------------------------------
echo "\n[prescription]\n";

$prescription = Database::selectOne(
    'SELECT * FROM prescriptions WHERE organization_id = :org AND encounter_id = :enc',
    ['org' => $orgId, 'enc' => $encounterId],
);

if ($prescription === null) {
    Database::statement(
        'INSERT INTO prescriptions
            (organization_id, encounter_id, patient_id, doctor_id, prescription_no,
             status, general_advice, issued_at, created_by, created_at, updated_at)
         VALUES (:org, :enc, :pid, :did, :no, \'issued\', :advice, :issued, :by, :now, :now)',
        [
            'org' => $orgId, 'enc' => $encounterId, 'pid' => $patientId, 'did' => $doctorId,
            'no' => $nextNo('prescriptions', 'prescription_no', 'RX', $orgId),
            'advice' => 'Brush twice daily with a soft brush, floss at night. '
                      . 'Avoid very cold drinks for a week. Come back sooner if the bleeding increases.',
            'issued' => gmdate('Y-m-d H:i:s', strtotime($visitDate . ' +30 minutes')),
            'by' => $actor, 'now' => now(),
        ],
    );
    $prescription = Database::selectOne(
        'SELECT * FROM prescriptions WHERE organization_id = :org AND encounter_id = :enc',
        ['org' => $orgId, 'enc' => $encounterId],
    );

    $lines = [
        ['Chlorhexidine 0.2% mouthwash', '10 ml', 'Twice daily', '14 days',
         'Rinse for 30 seconds, half an hour after brushing. Do not swallow.'],
        ['Metronidazole 400mg', '1 tablet', 'Three times daily', '5 days',
         'After food. No alcohol while taking this.'],
        ['Paracetamol 500mg', '1 tablet', 'As needed, up to 3 times daily', '3 days',
         'For pain only.'],
    ];

    foreach ($lines as $i => [$name, $dosage, $frequency, $duration, $instructions]) {
        // Link to the catalogue where it exists, so the row behaves like one
        // the doctor picked rather than typed.
        $med = Database::selectOne(
            'SELECT id FROM medications WHERE organization_id = :org AND name LIKE :n LIMIT 1',
            ['org' => $orgId, 'n' => explode(' ', $name)[0] . '%'],
        );

        Database::statement(
            'INSERT INTO prescription_items
                (organization_id, prescription_id, medication_id, medication_name,
                 dosage, frequency, duration, instructions, sort_order, created_at)
             VALUES (:org, :rx, :med, :name, :dosage, :freq, :dur, :inst, :sort, :now)',
            [
                'org' => $orgId, 'rx' => (int) $prescription['id'],
                'med' => $med === null ? null : (int) $med['id'],
                'name' => $name, 'dosage' => $dosage, 'freq' => $frequency,
                'dur' => $duration, 'inst' => $instructions, 'sort' => $i, 'now' => now(),
            ],
        );
    }
    echo "  created   {$prescription['prescription_no']} — " . count($lines) . " medicines\n";
} else {
    echo "  exists    {$prescription['prescription_no']}\n";
}

// ---------------------------------------------------------------
// The bills
//
// Two of them on purpose: one still owing and one settled, so the Bills
// screen shows both states and the "outstanding" figure on the dashboard is
// not zero.
// ---------------------------------------------------------------
echo "\n[bills]\n";

/**
 * Write an invoice and its lines from a list of service codes, totalling it
 * exactly as the billing service does: line by line, tax per line, and the
 * balance falling out of what has been paid.
 *
 * @param list<array{0:string,1:float}> $lines  [service code, quantity]
 */
$writeInvoice = static function (
    array $lines,
    string $issueDate,
    string $paidAmount,
    ?int $encounterId,
) use ($orgId, $patientId, $currency, $actor, $service, $priceOf, $nextInvoiceNo): ?array {

    $rows     = [];
    $subtotal = 0.0;
    $taxTotal = 0.0;

    foreach ($lines as [$code, $qty]) {
        $svc = $service($code, $orgId);
        if ($svc === null) {
            continue;
        }
        $unit    = (float) $priceOf((int) $svc['id']);
        $net     = $unit * $qty;
        $taxRate = (float) ($svc['tax_rate'] ?? 0);
        $tax     = round($net * $taxRate, 2);

        $rows[] = [
            'service_id'  => (int) $svc['id'],
            'code'        => $svc['code'],
            'description' => $svc['name'],
            'quantity'    => $qty,
            'unit_price'  => $unit,
            'tax_rate'    => $taxRate,
            'tax_amount'  => $tax,
            'line_total'  => round($net + $tax, 2),
        ];

        $subtotal += $net;
        $taxTotal += $tax;
    }

    if ($rows === []) {
        return null;
    }

    $grand   = round($subtotal + $taxTotal, 2);
    $paid    = round((float) $paidAmount, 2);
    $balance = round($grand - $paid, 2);
    $status  = $balance <= 0 ? 'paid' : ($paid > 0 ? 'partially_paid' : 'issued');

    $invoiceNo = $nextInvoiceNo($orgId);

    Database::statement(
        'INSERT INTO invoices
            (organization_id, patient_id, encounter_id, invoice_no, status, currency_code,
             subtotal, discount_total, tax_total, grand_total, paid_total,
             patient_payable, insurance_payable, issue_date, due_date,
             issued_by, created_by, updated_by, created_at, updated_at)
         VALUES (:org, :pid, :enc, :no, :status, :cur,
                 :sub, 0.00, :tax, :grand, :paid,
                 :grand2, 0.00, :issue, :due, :by, :by, :by, :now, :now)',
        [
            'org' => $orgId, 'pid' => $patientId, 'enc' => $encounterId,
            'no' => $invoiceNo, 'status' => $status, 'cur' => $currency,
            'sub' => number_format($subtotal, 2, '.', ''),
            'tax' => number_format($taxTotal, 2, '.', ''),
            'grand' => number_format($grand, 2, '.', ''),
            'grand2' => number_format($grand, 2, '.', ''),
            'paid' => number_format($paid, 2, '.', ''),
            'issue' => $issueDate,
            'due' => gmdate('Y-m-d', strtotime($issueDate . ' +14 days')),
            'by' => $actor, 'now' => now(),
        ],
    );

    $invoice = Database::selectOne(
        'SELECT * FROM invoices WHERE organization_id = :org AND invoice_no = :no',
        ['org' => $orgId, 'no' => $invoiceNo],
    );

    foreach ($rows as $i => $row) {
        Database::statement(
            'INSERT INTO invoice_items
                (organization_id, invoice_id, service_id, service_code, description,
                 quantity, unit_price, discount_amount, tax_rate, tax_amount,
                 line_total, is_ai_suggested, sort_order, created_at)
             VALUES (:org, :inv, :sid, :code, :desc,
                     :qty, :unit, 0.00, :rate, :tax,
                     :total, 0, :sort, :now)',
            [
                'org' => $orgId, 'inv' => (int) $invoice['id'], 'sid' => $row['service_id'],
                'code' => $row['code'], 'desc' => $row['description'],
                'qty' => number_format($row['quantity'], 2, '.', ''),
                'unit' => number_format($row['unit_price'], 2, '.', ''),
                'rate' => number_format($row['tax_rate'], 4, '.', ''),
                'tax' => number_format($row['tax_amount'], 2, '.', ''),
                'total' => number_format($row['line_total'], 2, '.', ''),
                'sort' => $i, 'now' => now(),
            ],
        );
    }

    if ($paid > 0) {
        $receipt = Database::selectOne(
            'SELECT COALESCE(MAX(CAST(SUBSTRING(receipt_no, 5) AS UNSIGNED)), 0) AS n
               FROM payments WHERE organization_id = :org AND receipt_no REGEXP \'^RCPT-[0-9]+$\'',
            ['org' => $orgId],
        );

        Database::statement(
            'INSERT INTO payments
                (organization_id, invoice_id, patient_id, receipt_no, method, status,
                 currency_code, amount, paid_at, received_by, created_at, updated_at)
             VALUES (:org, :inv, :pid, :receipt, \'cash\', \'succeeded\',
                     :cur, :amount, :paid_at, :by, :now, :now)',
            [
                'org' => $orgId, 'inv' => (int) $invoice['id'], 'pid' => $patientId,
                'receipt' => sprintf('RCPT-%06d', ((int) ($receipt['n'] ?? 0)) + 1),
                'cur' => $currency, 'amount' => number_format($paid, 2, '.', ''),
                'paid_at' => $issueDate . ' 12:00:00', 'by' => $actor, 'now' => now(),
            ],
        );
    }

    return $invoice;
};

$already = Database::selectOne(
    'SELECT COUNT(*) AS n FROM invoices WHERE organization_id = :org AND patient_id = :pid',
    ['org' => $orgId, 'pid' => $patientId],
);

if ((int) ($already['n'] ?? 0) > 0) {
    echo "  exists    this patient already has " . (int) $already['n'] . " invoice(s)\n";
} else {
    // The visit above: consultation plus the scaling that was done.
    $one = $writeInvoice(
        [['CONSULT-GEN', 1], ['DENT-SCALE', 1]],
        gmdate('Y-m-d', strtotime('-12 days')),
        '2000.00',
        $encounterId,
    );
    if ($one !== null) {
        echo "  created   {$one['invoice_no']} — {$one['grand_total']} $currency"
           . ", {$one['balance_due']} still owing\n";
    }

    // An older one, settled, so the screen is not all red.
    $two = $writeInvoice(
        [['CONSULT-GEN', 1]],
        gmdate('Y-m-d', strtotime('-3 months')),
        '99999.00',      // clipped to the total below
        null,
    );
    if ($two !== null) {
        // Paying more than the bill is not a thing; settle it exactly.
        Database::statement(
            // balance_due looks after itself — it is a generated column.
            'UPDATE invoices SET paid_total = grand_total,
                    status = \'paid\', updated_at = :now WHERE id = :id',
            ['id' => (int) $two['id'], 'now' => now()],
        );
        Database::statement(
            'UPDATE payments SET amount = (SELECT grand_total FROM invoices WHERE id = :id)
              WHERE invoice_id = :id2',
            ['id' => (int) $two['id'], 'id2' => (int) $two['id']],
        );
        echo "  created   {$two['invoice_no']} — paid in full\n";
    }
}

// ---------------------------------------------------------------
// A couple of things in the inbox
// ---------------------------------------------------------------
echo "\n[inbox]\n";

$userId = (int) $patient['user_id'];

$messages = [
    ['appointment.reminder', 'Appointment reminder',
     'You have an appointment with ' . $doctor['name'] . '. See you at the clinic.'],
    ['prescription.issued', 'Your prescription is ready',
     'Dr ' . $doctor['name'] . ' has issued a prescription from your last visit. '
     . 'You can read it under Records.'],
];

foreach ($messages as [$event, $title, $body]) {
    $exists = Database::selectOne(
        'SELECT id FROM notifications
          WHERE organization_id = :org AND user_id = :uid AND event = :event',
        ['org' => $orgId, 'uid' => $userId, 'event' => $event],
    );
    if ($exists !== null) {
        echo "  exists    $event\n";
        continue;
    }

    Database::statement(
        'INSERT INTO notifications
            (organization_id, user_id, channel, event, title, body, status,
             created_at, updated_at)
         VALUES (:org, :uid, \'in_app\', :event, :title, :body, \'sent\', :now, :now)',
        ['org' => $orgId, 'uid' => $userId, 'event' => $event,
         'title' => $title, 'body' => $body, 'now' => now()],
    );
    echo "  queued    $title\n";
}

echo "\n==================================\n";
echo "Done. Sign into the patient app as patient@demo.test / Password123 —\n";
echo "Home, Visits, Records and Bills all have something in them now.\n\n";
