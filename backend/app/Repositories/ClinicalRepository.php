<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Repository;
use App\Models\Medication;

/**
 * The reference and per-patient clinical lists that do not warrant a
 * repository each: the medication catalogue, allergies, medical conditions,
 * lab orders/results and medical documents.
 *
 * Grouped by what they are used for rather than split one-per-table, so the
 * consultation screen's supporting data has one obvious home.
 */
final class ClinicalRepository extends Repository
{
    protected string $model = Medication::class;
    // The base class needs a table for its generic helpers; every method here
    // names its own table explicitly.


    // ---------------- medication catalogue ----------------

    /** @return list<array<string,mixed>> */
    /**
     * The catalogue names behind a set of prescribed lines.
     *
     * The allergy check needs what a medicine IS, not what the client called
     * it on screen. `is_active` is not filtered here on purpose: a medicine
     * retired from the catalogue is still the medicine the patient reacts to.
     *
     * @param list<int> $ids
     * @return list<array<string,mixed>>
     */
    public function medicationNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn ($i): bool => (int) $i > 0)));
        if ($ids === []) {
            return [];
        }

        // Built from integers this method cast itself — no request value ever
        // reaches the string.
        $in = implode(',', array_map('intval', $ids));

        return Database::select(
            "SELECT id, name, brand_name FROM medications
              WHERE organization_id = :org AND id IN ($in)",
            ['org' => $this->scopeBinding()],
        );
    }

    public function medications(?string $search = null, int $limit = 50): array
    {
        $where    = ['organization_id = :org', 'is_active = 1'];
        $bindings = ['org' => $this->scopeBinding()];

        if ($search !== null && trim($search) !== '') {
            $where[]        = '(name LIKE :q OR brand_name LIKE :q)';
            $bindings['q']  = '%' . trim($search) . '%';
        }

        return Database::select(
            'SELECT * FROM medications
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY name
              LIMIT ' . max(1, min(200, $limit)),
            $bindings,
        );
    }

    // ---------------- allergies ----------------

    /** @return list<array<string,mixed>> */
    public function allergies(int $patientId): array
    {
        return Database::select(
            'SELECT * FROM allergies
              WHERE organization_id = :org AND patient_id = :pid
              ORDER BY is_active DESC,
                       FIELD(severity, \'life_threatening\',\'severe\',\'moderate\',\'mild\')',
            ['org' => $this->scopeBinding(), 'pid' => $patientId],
        );
    }

    /** @param array<string,mixed> $data */
    public function addAllergy(int $patientId, array $data, ?int $actorId): array
    {
        Database::statement(
            'INSERT INTO allergies
                (organization_id, patient_id, substance, reaction, severity,
                 noted_on, is_active, created_by, created_at, updated_at)
             VALUES (:org, :pid, :sub, :reaction, :sev, :noted, 1, :by, :now, :now)',
            [
                'org'      => $this->scopeBinding(),
                'pid'      => $patientId,
                'sub'      => $data['substance'],
                'reaction' => $data['reaction'] ?? null,
                'sev'      => $data['severity'] ?? 'mild',
                'noted'    => $data['noted_on'] ?? gmdate('Y-m-d'),
                'by'       => $actorId,
                'now'      => now(),
            ],
        );

        return Database::selectOne(
            'SELECT * FROM allergies WHERE id = :id',
            ['id' => Database::lastInsertId()],
        ) ?? [];
    }

    /**
     * §5: who took it off the chart, not only when. An allergy that quietly
     * stopped being an allergy is exactly the change somebody asks about
     * afterwards, and the answer belongs on the row rather than only in the
     * audit log nobody opens mid-consultation.
     */
    public function deactivateAllergy(int $patientId, int $allergyId, ?int $actorId = null): bool
    {
        return Database::statement(
            'UPDATE allergies SET is_active = 0, updated_by = :by, updated_at = :now
              WHERE organization_id = :org AND patient_id = :pid AND id = :id',
            [
                'by' => $actorId, 'now' => now(),
                'org' => $this->scopeBinding(), 'pid' => $patientId, 'id' => $allergyId,
            ],
        ) > 0;
    }

    // ---------------- medical conditions ----------------

    /** @return list<array<string,mixed>> */
    public function conditions(int $patientId): array
    {
        return Database::select(
            'SELECT * FROM medical_conditions
              WHERE organization_id = :org AND patient_id = :pid
              ORDER BY FIELD(status, \'active\',\'chronic\',\'resolved\'), diagnosed_on DESC',
            ['org' => $this->scopeBinding(), 'pid' => $patientId],
        );
    }

    /** @param array<string,mixed> $data */
    public function addCondition(int $patientId, array $data, ?int $actorId): array
    {
        Database::statement(
            'INSERT INTO medical_conditions
                (organization_id, patient_id, name, icd10_code, status,
                 diagnosed_on, notes, created_by, created_at, updated_at)
             VALUES (:org, :pid, :name, :icd, :status, :dx, :notes, :by, :now, :now)',
            [
                'org'    => $this->scopeBinding(),
                'pid'    => $patientId,
                'name'   => $data['name'],
                'icd'    => $data['icd10_code'] ?? null,
                'status' => $data['status'] ?? 'active',
                'dx'     => $data['diagnosed_on'] ?? null,
                'notes'  => $data['notes'] ?? null,
                'by'     => $actorId,
                'now'    => now(),
            ],
        );

        return Database::selectOne(
            'SELECT * FROM medical_conditions WHERE id = :id',
            ['id' => Database::lastInsertId()],
        ) ?? [];
    }

    /** §5: who moved it from active to resolved, and back. */
    public function setConditionStatus(
        int $patientId,
        int $conditionId,
        string $status,
        ?int $actorId = null,
    ): bool {
        return Database::statement(
            'UPDATE medical_conditions
                SET status = :status, updated_by = :by, updated_at = :now
              WHERE organization_id = :org AND patient_id = :pid AND id = :id',
            [
                'status' => $status, 'by' => $actorId, 'now' => now(),
                'org' => $this->scopeBinding(), 'pid' => $patientId, 'id' => $conditionId,
            ],
        ) > 0;
    }

    // ---------------- lab orders & results ----------------

    public function nextLabOrderNo(): string
    {
        $row = Database::selectOne(
            'SELECT COALESCE(MAX(CAST(SUBSTRING(order_no, 4) AS UNSIGNED)), 0) AS n
               FROM lab_orders
              WHERE organization_id = :org AND order_no REGEXP \'^LO-[0-9]+$\'',
            ['org' => $this->scopeBinding()],
        );
        return sprintf('LO-%06d', ((int) ($row['n'] ?? 0)) + 1);
    }

    /** @param array<string,mixed> $data */
    public function createLabOrder(array $data, ?int $actorId): array
    {
        Database::statement(
            'INSERT INTO lab_orders
                (organization_id, encounter_id, patient_id, doctor_id, order_no,
                 status, priority, clinical_notes, ordered_at, created_by, created_at, updated_at)
             VALUES (:org, :eid, :pid, :did, :no, \'ordered\', :prio, :notes, :now, :by, :now, :now)',
            [
                'org'   => $this->scopeBinding(),
                'eid'   => $data['encounter_id'] ?? null,
                'pid'   => $data['patient_id'],
                'did'   => $data['doctor_id'] ?? null,
                'no'    => $this->nextLabOrderNo(),
                'prio'  => $data['priority'] ?? 'routine',
                'notes' => $data['clinical_notes'] ?? null,
                'by'    => $actorId,
                'now'   => now(),
            ],
        );

        $orderId = Database::lastInsertId();

        // The tests themselves — what the patient reads on their phone and
        // what the lab is asked to do. A price where the clinic's lab has one.
        foreach ($data['tests'] ?? [] as $test) {
            Database::statement(
                'INSERT INTO lab_order_tests (organization_id, lab_order_id, test_name, price, created_at)
                 VALUES (:org, :lid, :name, :price, :now)',
                [
                    'org'   => $this->scopeBinding(),
                    'lid'   => $orderId,
                    'name'  => trim((string) $test['name']),
                    'price' => isset($test['price']) && $test['price'] !== '' ? $test['price'] : null,
                    'now'   => now(),
                ],
            );
        }

        return $this->findLabOrder($orderId) ?? [];
    }

    public function findLabOrder(int $id): ?array
    {
        $row = Database::selectOne(
            'SELECT * FROM lab_orders WHERE organization_id = :org AND id = :id',
            ['org' => $this->scopeBinding(), 'id' => $id],
        );
        if ($row !== null) {
            $row['tests'] = $this->testsFor((int) $row['id']);
        }
        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function testsFor(int $labOrderId): array
    {
        return Database::select(
            'SELECT id, test_name, price FROM lab_order_tests
              WHERE organization_id = :org AND lab_order_id = :lid ORDER BY id',
            ['org' => $this->scopeBinding(), 'lid' => $labOrderId],
        );
    }

    /**
     * One lab order, as a person would want to read it (§19).
     *
     * findLabOrder() returns the bare row and exists for permission checks.
     * This is the one for a screen: who it is for, who ordered it, and what
     * came back.
     *
     * @return array<string,mixed>|null
     */
    public function findLabOrderDetailed(int $id): ?array
    {
        $order = Database::selectOne(
            'SELECT lo.*,
                    CONCAT(p.first_name, \' \', p.last_name) AS patient_name, p.mrn,
                    u.name AS doctor_name, e.encounter_no
               FROM lab_orders lo
               JOIN patients p        ON p.id = lo.patient_id
               LEFT JOIN doctors d    ON d.id = lo.doctor_id
               LEFT JOIN users u      ON u.id = d.user_id
               LEFT JOIN encounters e ON e.id = lo.encounter_id
              WHERE lo.organization_id = :org AND lo.id = :id',
            ['org' => $this->scopeBinding(), 'id' => $id],
        );

        if ($order === null) {
            return null;
        }

        $order['results'] = Database::select(
            'SELECT * FROM lab_results
              WHERE organization_id = :org AND lab_order_id = :lid ORDER BY id',
            ['org' => $this->scopeBinding(), 'lid' => $id],
        );

        return $order;
    }

    /**
     * Results themselves, across orders (§19).
     *
     * The order-centric list cannot answer the question a clinician actually
     * asks — "has anything come back abnormal?" — because an order carrying one
     * critical value among eight normal ones looks like any other completed
     * order. This is result-first, so `?flag=critical` is a real query.
     *
     * @param array{patient_id?:int,flag?:string,from?:string,to?:string} $filters
     * @return list<array<string,mixed>>
     */
    public function labResults(array $filters): array
    {
        $where    = ['lr.organization_id = :org'];
        $bindings = ['org' => $this->scopeBinding()];

        if (!empty($filters['patient_id'])) {
            $where[]         = 'lr.patient_id = :pid';
            $bindings['pid'] = (int) $filters['patient_id'];
        }
        if (!empty($filters['flag'])) {
            $where[]          = 'lr.flag = :flag';
            $bindings['flag'] = $filters['flag'];
        }
        if (!empty($filters['from'])) {
            $where[]          = 'lr.reported_at >= :from';
            $bindings['from'] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $where[]        = 'lr.reported_at <= :to';
            $bindings['to'] = $filters['to'] . ' 23:59:59';
        }

        return Database::select(
            'SELECT lr.*, lo.order_no, lo.status AS order_status, lo.priority,
                    CONCAT(p.first_name, \' \', p.last_name) AS patient_name, p.mrn,
                    u.name AS reported_by_name
               FROM lab_results lr
               JOIN lab_orders lo ON lo.id = lr.lab_order_id
               JOIN patients p    ON p.id = lr.patient_id
               LEFT JOIN users u  ON u.id = lr.reported_by
              WHERE ' . implode(' AND ', $where) . '
              -- Worst first: a critical value is why anyone opens this screen.
              ORDER BY FIELD(lr.flag, \'critical\',\'high\',\'low\',\'normal\'),
                       lr.reported_at DESC
              LIMIT 200',
            $bindings,
        );
    }

    /** @return list<array<string,mixed>> */
    public function labOrders(array $filters): array
    {
        $where    = ['lo.organization_id = :org'];
        $bindings = ['org' => $this->scopeBinding()];

        foreach (['patient_id' => 'lo.patient_id', 'status' => 'lo.status'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[]        = "$column = :$key";
                $bindings[$key] = $filters[$key];
            }
        }

        $rows = Database::select(
            'SELECT lo.*,
                    CONCAT(p.first_name, \' \', p.last_name) AS patient_name, p.mrn
               FROM lab_orders lo
               JOIN patients p ON p.id = lo.patient_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY FIELD(lo.priority, \'stat\',\'urgent\',\'routine\'), lo.created_at DESC
              LIMIT 200',
            $bindings,
        );

        foreach ($rows as $i => $row) {
            $rows[$i]['tests']   = $this->testsFor((int) $row['id']);
            $rows[$i]['results'] = Database::select(
                'SELECT * FROM lab_results
                  WHERE organization_id = :org AND lab_order_id = :lid ORDER BY id',
                ['org' => $this->scopeBinding(), 'lid' => (int) $row['id']],
            );
        }

        return $rows;
    }

    /** @param list<array<string,mixed>> $results */
    public function recordLabResults(
        int $labOrderId,
        int $patientId,
        array $results,
        ?int $actorId,
        ?string $labName = null,
        ?string $totalCharge = null,
    ): void {
        $org = $this->scopeBinding();

        Database::transaction(function () use (
            $org, $labOrderId, $patientId, $results, $actorId, $labName, $totalCharge
        ): void {
            foreach ($results as $r) {
                Database::statement(
                    'INSERT INTO lab_results
                        (organization_id, lab_order_id, patient_id, test_name, value, unit,
                         reference_range, flag, comments, reported_at, reported_by,
                         created_at, updated_at)
                     VALUES (:org, :lid, :pid, :test, :val, :unit, :ref, :flag, :comments,
                             :now, :by, :now, :now)',
                    [
                        'org'      => $org,
                        'lid'      => $labOrderId,
                        'pid'      => $patientId,
                        'test'     => $r['test_name'],
                        'val'      => $r['value'] ?? null,
                        'unit'     => $r['unit'] ?? null,
                        'ref'      => $r['reference_range'] ?? null,
                        'flag'     => $r['flag'] ?? null,
                        'comments' => $r['comments'] ?? null,
                        'by'       => $actorId,
                        'now'      => now(),
                    ],
                );
            }

            Database::statement(
                'UPDATE lab_orders
                    SET status = \'completed\', completed_at = :now,
                        lab_name = COALESCE(:lab, lab_name),
                        total_charge = COALESCE(:charge, total_charge),
                        updated_by = :by, updated_at = :now2
                  WHERE organization_id = :org AND id = :id',
                [
                    'now' => now(), 'now2' => now(), 'by' => $actorId,
                    'lab' => $labName, 'charge' => $totalCharge,
                    'org' => $org, 'id' => $labOrderId,
                ],
            );
        });
    }

    // ---------------- documents (§19: metadata here, bytes on disk) ----------------

    /** @param array<string,mixed> $data */
    public function addDocument(array $data, ?int $actorId): array
    {
        Database::statement(
            'INSERT INTO medical_documents
                (organization_id, patient_id, encounter_id, category, title,
                 storage_path, mime_type, size_bytes, checksum_sha256, visibility,
                 uploaded_by, created_at, updated_at)
             VALUES (:org, :pid, :eid, :cat, :title, :path, :mime, :size, :sum, :vis,
                     :by, :now, :now)',
            [
                'org'   => $this->scopeBinding(),
                'pid'   => $data['patient_id'],
                'eid'   => $data['encounter_id'] ?? null,
                'cat'   => $data['category'] ?? 'other',
                'title' => $data['title'],
                'path'  => $data['storage_path'],
                'mime'  => $data['mime_type'],
                'size'  => $data['size_bytes'],
                'sum'   => $data['checksum_sha256'] ?? null,
                'vis'   => $data['visibility'] ?? 'clinic_only',
                'by'    => $actorId,
                'now'   => now(),
            ],
        );

        return Database::selectOne(
            'SELECT * FROM medical_documents WHERE id = :id',
            ['id' => Database::lastInsertId()],
        ) ?? [];
    }

    /** @return list<array<string,mixed>> */
    public function documents(int $patientId, bool $patientVisibleOnly = false): array
    {
        $where = ['organization_id = :org', 'patient_id = :pid'];
        if ($patientVisibleOnly) {
            $where[] = "visibility = 'patient_visible'";
        }

        return Database::select(
            'SELECT * FROM medical_documents
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY created_at DESC',
            ['org' => $this->scopeBinding(), 'pid' => $patientId],
        );
    }

    public function findDocument(int $id): ?array
    {
        return Database::selectOne(
            'SELECT * FROM medical_documents WHERE organization_id = :org AND id = :id',
            ['org' => $this->scopeBinding(), 'id' => $id],
        );
    }
}
