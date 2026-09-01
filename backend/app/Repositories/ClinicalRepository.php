<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Repository;

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
    // The base class needs a table for its generic helpers; every method here
    // names its own table explicitly.
    protected string $table = 'medications';

    protected array $fillable = [
        'name', 'brand_name', 'form', 'strength',
        'default_dosage', 'default_frequency', 'default_duration',
        'is_active', 'created_at', 'updated_at',
    ];

    // ---------------- medication catalogue ----------------

    /** @return list<array<string,mixed>> */
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

    public function deactivateAllergy(int $patientId, int $allergyId): bool
    {
        return Database::statement(
            'UPDATE allergies SET is_active = 0, updated_at = :now
              WHERE organization_id = :org AND patient_id = :pid AND id = :id',
            ['now' => now(), 'org' => $this->scopeBinding(), 'pid' => $patientId, 'id' => $allergyId],
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

    public function setConditionStatus(int $patientId, int $conditionId, string $status): bool
    {
        return Database::statement(
            'UPDATE medical_conditions SET status = :status, updated_at = :now
              WHERE organization_id = :org AND patient_id = :pid AND id = :id',
            [
                'status' => $status, 'now' => now(),
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

        return Database::selectOne(
            'SELECT * FROM lab_orders WHERE id = :id',
            ['id' => Database::lastInsertId()],
        ) ?? [];
    }

    public function findLabOrder(int $id): ?array
    {
        return Database::selectOne(
            'SELECT * FROM lab_orders WHERE organization_id = :org AND id = :id',
            ['org' => $this->scopeBinding(), 'id' => $id],
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
            $rows[$i]['results'] = Database::select(
                'SELECT * FROM lab_results
                  WHERE organization_id = :org AND lab_order_id = :lid ORDER BY id',
                ['org' => $this->scopeBinding(), 'lid' => (int) $row['id']],
            );
        }

        return $rows;
    }

    /** @param list<array<string,mixed>> $results */
    public function recordLabResults(int $labOrderId, int $patientId, array $results, ?int $actorId): void
    {
        $org = $this->scopeBinding();

        Database::transaction(function () use ($org, $labOrderId, $patientId, $results, $actorId): void {
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
                    SET status = \'completed\', completed_at = :now, updated_at = :now
                  WHERE organization_id = :org AND id = :id',
                ['now' => now(), 'org' => $org, 'id' => $labOrderId],
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
