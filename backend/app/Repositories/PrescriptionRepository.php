<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Repository;
use App\Models\Prescription;

/**
 * Prescriptions and their line items (§4).
 *
 * Items are written through the parent because a prescription is only
 * meaningful as a whole: header plus lines, issued together.
 */
final class PrescriptionRepository extends Repository
{
    protected string $model = Prescription::class;

    public function nextPrescriptionNo(): string
    {
        $row = Database::selectOne(
            'SELECT COALESCE(MAX(CAST(SUBSTRING(prescription_no, 4) AS UNSIGNED)), 0) AS n
               FROM prescriptions
              WHERE organization_id = :org AND prescription_no REGEXP \'^RX-[0-9]+$\'',
            ['org' => $this->scopeBinding()],
        );

        return sprintf('RX-%06d', ((int) ($row['n'] ?? 0)) + 1);
    }

    /** @param list<array<string,mixed>> $items */
    public function replaceItems(int $prescriptionId, array $items): void
    {
        $org = $this->scopeBinding();

        Database::transaction(function () use ($org, $prescriptionId, $items): void {
            Database::statement(
                'DELETE FROM prescription_items
                  WHERE organization_id = :org AND prescription_id = :rid',
                ['org' => $org, 'rid' => $prescriptionId],
            );

            foreach (array_values($items) as $i => $item) {
                Database::statement(
                    'INSERT INTO prescription_items
                        (organization_id, prescription_id, medication_id, medication_name,
                         dosage, frequency, duration, instructions, sort_order, created_at)
                     VALUES (:org, :rid, :mid, :name, :dosage, :freq, :dur, :inst, :sort, :now)',
                    [
                        'org'    => $org,
                        'rid'    => $prescriptionId,
                        'mid'    => $item['medication_id'] ?? null,
                        // Name is snapshotted: editing the catalogue later must
                        // not change what was actually prescribed.
                        'name'   => $item['medication_name'],
                        'dosage' => $item['dosage']       ?? null,
                        'freq'   => $item['frequency']    ?? null,
                        'dur'    => $item['duration']     ?? null,
                        'inst'   => $item['instructions'] ?? null,
                        'sort'   => $i,
                        'now'    => now(),
                    ],
                );
            }
        });
    }

    /** @return list<array<string,mixed>> */
    public function items(int $prescriptionId): array
    {
        return Database::select(
            'SELECT * FROM prescription_items
              WHERE organization_id = :org AND prescription_id = :rid
              ORDER BY sort_order, id',
            ['org' => $this->scopeBinding(), 'rid' => $prescriptionId],
        );
    }

    public function findDetailed(int $id): ?array
    {
        $row = Database::selectOne(
            'SELECT rx.*,
                    CONCAT(p.first_name, \' \', p.last_name) AS patient_name,
                    p.mrn, p.date_of_birth, p.gender,
                    TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) AS patient_age,
                    u.name AS doctor_name, d.specialty, d.qualification,
                    e.encounter_no, e.chief_complaint
               FROM prescriptions rx
               JOIN patients p ON p.id = rx.patient_id
               JOIN doctors  d ON d.id = rx.doctor_id
               JOIN users    u ON u.id = d.user_id
               JOIN encounters e ON e.id = rx.encounter_id
              WHERE rx.organization_id = :org AND rx.id = :id',
            ['org' => $this->scopeBinding(), 'id' => $id],
        );

        if ($row === null) {
            return null;
        }

        $row['items'] = $this->items($id);

        // Diagnoses give the prescription its clinical context on the printout.
        $row['diagnoses'] = Database::select(
            'SELECT description, icd10_code, type FROM diagnoses
              WHERE organization_id = :org AND encounter_id = :eid',
            ['org' => $this->scopeBinding(), 'eid' => (int) $row['encounter_id']],
        );

        return $row;
    }

    /**
     * The clinic's prescriptions, filtered and paged (§19).
     *
     * Items are deliberately NOT loaded here, unlike forPatient(): fifty
     * prescriptions would mean fifty extra queries, and a list screen shows
     * how many drugs are on each, not what they are. Hence the count subquery.
     *
     * @param array{patient_id?:int,doctor_id?:int,status?:string,from?:string,to?:string} $filters
     * @return array{data: list<array<string,mixed>>, meta: array<string,int>}
     */
    public function search(array $filters, int $page, int $perPage): array
    {
        $where    = ['rx.organization_id = :org'];
        $bindings = ['org' => $this->scopeBinding()];

        foreach (['patient_id' => 'rx.patient_id', 'doctor_id' => 'rx.doctor_id'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[]        = "$column = :$key";
                $bindings[$key] = (int) $filters[$key];
            }
        }
        if (!empty($filters['status'])) {
            $where[]            = 'rx.status = :status';
            $bindings['status'] = $filters['status'];
        }
        if (!empty($filters['from'])) {
            $where[]          = 'rx.created_at >= :from';
            $bindings['from'] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $where[]        = 'rx.created_at <= :to';
            $bindings['to'] = $filters['to'] . ' 23:59:59';
        }

        $clause = implode(' AND ', $where);

        $total = (int) (Database::selectOne(
            "SELECT COUNT(*) AS c FROM prescriptions rx WHERE $clause",
            $bindings,
        )['c'] ?? 0);

        $rows = Database::select(
            "SELECT rx.*, u.name AS doctor_name, d.specialty, e.encounter_no,
                    CONCAT(p.first_name, ' ', p.last_name) AS patient_name, p.mrn,
                    (SELECT COUNT(*) FROM prescription_items i
                      WHERE i.prescription_id = rx.id) AS item_count
               FROM prescriptions rx
               JOIN doctors d  ON d.id = rx.doctor_id
               JOIN users   u  ON u.id = d.user_id
               JOIN patients p ON p.id = rx.patient_id
               LEFT JOIN encounters e ON e.id = rx.encounter_id
              WHERE $clause
              ORDER BY rx.created_at DESC
              LIMIT " . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage),
            $bindings,
        );

        return [
            'data' => $rows,
            'meta' => [
                'page'      => $page,
                'per_page'  => $perPage,
                'total'     => $total,
                'last_page' => (int) max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    public function forPatient(int $patientId): array
    {
        $rows = Database::select(
            'SELECT rx.*, u.name AS doctor_name, d.specialty, e.encounter_no
               FROM prescriptions rx
               JOIN doctors d ON d.id = rx.doctor_id
               JOIN users   u ON u.id = d.user_id
               JOIN encounters e ON e.id = rx.encounter_id
              WHERE rx.organization_id = :org AND rx.patient_id = :pid
              ORDER BY rx.created_at DESC',
            ['org' => $this->scopeBinding(), 'pid' => $patientId],
        );

        foreach ($rows as $i => $row) {
            $rows[$i]['items'] = $this->items((int) $row['id']);
        }

        return $rows;
    }
}
