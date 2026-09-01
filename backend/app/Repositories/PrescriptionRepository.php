<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Repository;

/**
 * Prescriptions and their line items (§4).
 *
 * Items are written through the parent because a prescription is only
 * meaningful as a whole: header plus lines, issued together.
 */
final class PrescriptionRepository extends Repository
{
    protected string $table = 'prescriptions';

    protected array $fillable = [
        'encounter_id', 'patient_id', 'doctor_id', 'prescription_no', 'status',
        'general_advice', 'pdf_path', 'issued_at', 'created_by', 'created_at', 'updated_at',
    ];

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
