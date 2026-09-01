<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Repository;

final class PatientRepository extends Repository
{
    protected string $table = 'patients';

    protected array $fillable = [
        'user_id', 'mrn', 'first_name', 'last_name', 'date_of_birth', 'gender',
        'phone', 'email', 'address', 'city', 'blood_group',
        'emergency_name', 'emergency_phone', 'emergency_relation',
        'notes', 'status', 'created_by', 'updated_by', 'created_at', 'updated_at',
    ];

    /**
     * Next Medical Record Number for this tenant.
     *
     * MRNs are per-organization (the unique key is (organization_id, mrn)), so
     * two clinics both having P-000001 is correct and expected.
     *
     * MAX(...)+1 is taken inside the caller's transaction; the unique key is
     * the real guard, so a lost race surfaces as a duplicate-key error rather
     * than two patients quietly sharing a number.
     */
    public function nextMrn(): string
    {
        $row = Database::selectOne(
            'SELECT COALESCE(MAX(CAST(SUBSTRING(mrn, 3) AS UNSIGNED)), 0) AS n
               FROM patients
              WHERE organization_id = :org AND mrn REGEXP \'^P-[0-9]+$\'',
            ['org' => $this->scopeBinding()],
        );

        return sprintf('P-%06d', ((int) ($row['n'] ?? 0)) + 1);
    }

    /**
     * Search by name, MRN or phone — what a receptionist actually types.
     *
     * @return array{data: list<array<string,mixed>>, meta: array<string,int>}
     */
    public function search(?string $term, ?string $status, int $page, int $perPage): array
    {
        $where    = ['p.organization_id = :org'];
        $bindings = ['org' => $this->scopeBinding()];

        if ($term !== null && trim($term) !== '') {
            $where[]        = "(CONCAT(p.first_name, ' ', p.last_name) LIKE :q
                                OR p.mrn LIKE :q OR p.phone LIKE :q OR p.email LIKE :q)";
            $bindings['q']  = '%' . trim($term) . '%';
        }
        if ($status !== null && $status !== '') {
            $where[]             = 'p.status = :status';
            $bindings['status']  = $status;
        }

        $clause  = ' WHERE ' . implode(' AND ', $where);
        $perPage = max(1, min(100, $perPage));
        $offset  = (max(1, $page) - 1) * $perPage;

        $total = (int) (Database::selectOne(
            'SELECT COUNT(*) AS c FROM patients p' . $clause,
            $bindings,
        )['c'] ?? 0);

        $rows = Database::select(
            'SELECT p.*,
                    TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) AS age,
                    (SELECT COUNT(*) FROM encounters e
                      WHERE e.patient_id = p.id) AS visit_count,
                    (SELECT MAX(e.created_at) FROM encounters e
                      WHERE e.patient_id = p.id) AS last_visit_at
               FROM patients p'
            . $clause
            . ' ORDER BY p.created_at DESC
                LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $bindings,
        );

        return [
            'data' => $rows,
            'meta' => [
                'page'      => max(1, $page),
                'per_page'  => $perPage,
                'total'     => $total,
                'last_page' => (int) max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    /**
     * Everything a doctor needs on the patient header, in one call:
     * demographics, allergies and active conditions.
     *
     * Allergies come first on purpose — prescribing against a known allergy is
     * the error this screen exists to prevent.
     *
     * @return array<string,mixed>|null
     */
    public function withClinicalSummary(int $patientId): ?array
    {
        $patient = $this->find($patientId);
        if ($patient === null) {
            return null;
        }

        $org = $this->scopeBinding();

        $patient['age'] = $patient['date_of_birth'] !== null
            ? (int) (Database::selectOne(
                'SELECT TIMESTAMPDIFF(YEAR, :dob, CURDATE()) AS a',
                ['dob' => $patient['date_of_birth']],
            )['a'] ?? 0)
            : null;

        $patient['allergies'] = Database::select(
            'SELECT * FROM allergies
              WHERE organization_id = :org AND patient_id = :pid AND is_active = 1
              ORDER BY FIELD(severity, \'life_threatening\',\'severe\',\'moderate\',\'mild\')',
            ['org' => $org, 'pid' => $patientId],
        );

        $patient['conditions'] = Database::select(
            'SELECT * FROM medical_conditions
              WHERE organization_id = :org AND patient_id = :pid AND status <> \'resolved\'
              ORDER BY diagnosed_on DESC',
            ['org' => $org, 'pid' => $patientId],
        );

        $patient['recent_encounters'] = Database::select(
            'SELECT e.id, e.encounter_no, e.type, e.status, e.chief_complaint,
                    e.created_at, e.completed_at,
                    u.name AS doctor_name, d.specialty
               FROM encounters e
               JOIN doctors d ON d.id = e.doctor_id
               JOIN users   u ON u.id = d.user_id
              WHERE e.organization_id = :org AND e.patient_id = :pid
              ORDER BY e.created_at DESC
              LIMIT 10',
            ['org' => $org, 'pid' => $patientId],
        );

        return $patient;
    }

    /** The patient row linked to a login, used by the patient mobile app. */
    public function forUser(int $userId): ?array
    {
        return $this->firstWhere(['user_id' => $userId]);
    }
}
