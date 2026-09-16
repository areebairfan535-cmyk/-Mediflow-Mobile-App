<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Repository;
use App\Models\Encounter;

/**
 * Encounters — the hub of the clinical model (§5).
 *
 * This repository also reads the rows that hang off an encounter (diagnoses,
 * procedures, clinical notes). They have no life of their own: a diagnosis
 * without its encounter is meaningless, so they are loaded and written through
 * the aggregate rather than getting their own repositories.
 */
final class EncounterRepository extends Repository
{
    protected string $model = Encounter::class;

    public function nextEncounterNo(): string
    {
        $row = Database::selectOne(
            'SELECT COALESCE(MAX(CAST(SUBSTRING(encounter_no, 3) AS UNSIGNED)), 0) AS n
               FROM encounters
              WHERE organization_id = :org AND encounter_no REGEXP \'^E-[0-9]+$\'',
            ['org' => $this->scopeBinding()],
        );

        return sprintf('E-%06d', ((int) ($row['n'] ?? 0)) + 1);
    }

    /** @return list<array<string,mixed>> */
    public function listDetailed(array $filters, int $limit = 100): array
    {
        $where    = ['e.organization_id = :org'];
        $bindings = ['org' => $this->scopeBinding()];

        foreach (['patient_id' => 'e.patient_id', 'doctor_id' => 'e.doctor_id', 'status' => 'e.status'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[]          = "$column = :$key";
                $bindings[$key]   = $filters[$key];
            }
        }

        return Database::select(
            'SELECT e.*,
                    CONCAT(p.first_name, \' \', p.last_name) AS patient_name,
                    p.mrn,
                    u.name AS doctor_name, d.specialty,
                    (SELECT COUNT(*) FROM diagnoses dg WHERE dg.encounter_id = e.id) AS diagnosis_count,
                    (SELECT COUNT(*) FROM prescriptions rx WHERE rx.encounter_id = e.id) AS prescription_count
               FROM encounters e
               JOIN patients p ON p.id = e.patient_id
               JOIN doctors  d ON d.id = e.doctor_id
               JOIN users    u ON u.id = d.user_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY e.created_at DESC
              LIMIT ' . max(1, min(200, $limit)),
            $bindings,
        );
    }

    /**
     * One encounter with everything recorded during it. This is the payload
     * the consultation screen renders, in a single round trip.
     *
     * @return array<string,mixed>|null
     */
    public function fullRecord(int $encounterId): ?array
    {
        $org = $this->scopeBinding();

        $encounter = Database::selectOne(
            'SELECT e.*,
                    CONCAT(p.first_name, \' \', p.last_name) AS patient_name,
                    p.mrn, p.date_of_birth, p.gender, p.blood_group, p.phone AS patient_phone,
                    TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) AS patient_age,
                    u.name AS doctor_name, d.specialty
               FROM encounters e
               JOIN patients p ON p.id = e.patient_id
               JOIN doctors  d ON d.id = e.doctor_id
               JOIN users    u ON u.id = d.user_id
              WHERE e.organization_id = :org AND e.id = :id',
            ['org' => $org, 'id' => $encounterId],
        );

        if ($encounter === null) {
            return null;
        }

        $args = ['org' => $org, 'eid' => $encounterId];

        $encounter['diagnoses'] = Database::select(
            'SELECT * FROM diagnoses
              WHERE organization_id = :org AND encounter_id = :eid
              ORDER BY FIELD(type, \'primary\',\'secondary\',\'provisional\',\'differential\'), id',
            $args,
        );

        $encounter['procedures'] = Database::select(
            'SELECT * FROM procedures
              WHERE organization_id = :org AND encounter_id = :eid ORDER BY id',
            $args,
        );

        $encounter['notes'] = Database::select(
            'SELECT * FROM clinical_notes
              WHERE organization_id = :org AND encounter_id = :eid ORDER BY id',
            $args,
        );

        $encounter['lab_orders'] = Database::select(
            'SELECT lo.*,
                    (SELECT COUNT(*) FROM lab_results lr WHERE lr.lab_order_id = lo.id) AS result_count,
                    (SELECT GROUP_CONCAT(t.test_name ORDER BY t.id SEPARATOR \', \')
                       FROM lab_order_tests t WHERE t.lab_order_id = lo.id) AS test_names
               FROM lab_orders lo
              WHERE lo.organization_id = :org AND lo.encounter_id = :eid
              ORDER BY lo.id',
            $args,
        );

        $encounter['prescriptions'] = Database::select(
            'SELECT * FROM prescriptions
              WHERE organization_id = :org AND encounter_id = :eid ORDER BY id',
            $args,
        );

        foreach ($encounter['prescriptions'] as $i => $rx) {
            $encounter['prescriptions'][$i]['items'] = Database::select(
                'SELECT * FROM prescription_items
                  WHERE organization_id = :org AND prescription_id = :rid
                  ORDER BY sort_order, id',
                ['org' => $org, 'rid' => (int) $rx['id']],
            );
        }

        /**
         * What happened last time (§4).
         *
         * The workflow asks the doctor to check previous visits before
         * diagnosing, and until this was here the only way to do that was the
         * Full chart button — which leaves the consultation. Nobody navigates
         * away from a half-written note to read history, so in practice that
         * step was skipped.
         *
         * Five is enough to see a pattern and short enough to read at a
         * glance. Completed only: an abandoned consultation from last week is
         * not history, it is a mistake somebody left open.
         */
        $encounter['previous_visits'] = Database::select(
            'SELECT e.id, e.encounter_no, e.type, e.chief_complaint,
                    e.created_at, e.completed_at, e.followup_on,
                    u.name AS doctor_name, d.specialty
               FROM encounters e
               JOIN doctors d ON d.id = e.doctor_id
               JOIN users   u ON u.id = d.user_id
              WHERE e.organization_id = :org
                AND e.patient_id = :pid
                AND e.id <> :eid
                AND e.status = \'completed\'
              ORDER BY COALESCE(e.completed_at, e.created_at) DESC
              LIMIT 5',
            ['org' => $org, 'pid' => (int) $encounter['patient_id'], 'eid' => $encounterId],
        );

        // What each of those visits concluded. A date with no diagnosis beside
        // it tells the doctor nothing they could act on.
        foreach ($encounter['previous_visits'] as $i => $visit) {
            $encounter['previous_visits'][$i]['diagnoses'] = Database::select(
                'SELECT description, icd10_code, type FROM diagnoses
                  WHERE organization_id = :org AND encounter_id = :eid
                  ORDER BY FIELD(type, \'primary\',\'secondary\',\'provisional\',\'differential\'), id',
                ['org' => $org, 'eid' => (int) $visit['id']],
            );
        }

        return $encounter;
    }

    /** The clinician's open consultation, if any — used to resume after a reload. */
    public function openForDoctor(int $doctorId): ?array
    {
        return $this->firstWhere(['doctor_id' => $doctorId, 'status' => 'open']);
    }

    /**
     * The diagnoses this clinic actually writes, commonest first (§4).
     *
     * There is no diagnosis catalogue to ship — "the things a dental clinic
     * diagnoses" is not a list anyone can write in advance, and one bought in
     * would be wrong for every practice in a different way. What a clinic HAS
     * diagnosed is a better list than any of them, it needs no maintaining,
     * and it is right from the second visit onwards.
     *
     * Grouped on the pair, so the same wording under two ICD-10 codes stays
     * two entries — that difference is usually the point.
     *
     * @return list<array<string,mixed>>
     */
    public function commonDiagnoses(?string $search = null, int $limit = 20): array
    {
        $where    = ['organization_id = :org', "TRIM(description) <> ''"];
        $bindings = ['org' => $this->scopeBinding()];

        if ($search !== null && trim($search) !== '') {
            $where[]       = '(description LIKE :q OR icd10_code LIKE :q)';
            $bindings['q'] = '%' . trim($search) . '%';
        }

        return Database::select(
            'SELECT description, icd10_code, COUNT(*) AS times_used
               FROM diagnoses
              WHERE ' . implode(' AND ', $where) . '
              GROUP BY description, icd10_code
              ORDER BY times_used DESC, description
              LIMIT ' . max(1, min(50, $limit)),
            $bindings,
        );
    }

    // ---- child writes ----

    /** @param array<string,mixed> $data */
    public function addDiagnosis(array $data): array
    {
        return $this->insertChild('diagnoses', $data, [
            'encounter_id', 'patient_id', 'icd10_code', 'description', 'type', 'notes', 'created_by',
        ]);
    }

    /** @param array<string,mixed> $data */
    public function addProcedure(array $data): array
    {
        return $this->insertChild('procedures', $data, [
            'encounter_id', 'patient_id', 'doctor_id', 'service_id', 'name',
            'cpt_code', 'site', 'outcome', 'performed_at', 'created_by',
        ]);
    }

    /** @param array<string,mixed> $data */
    public function addNote(array $data): array
    {
        return $this->insertChild('clinical_notes', $data, [
            'encounter_id', 'patient_id', 'type', 'body', 'is_ai_drafted',
            'approved_by', 'approved_at', 'created_by',
        ]);
    }

    public function deleteChild(string $table, int $encounterId, int $id): bool
    {
        if (!in_array($table, ['diagnoses', 'procedures', 'clinical_notes'], true)) {
            throw new \InvalidArgumentException("Not an encounter child table: $table");
        }
        return Database::statement(
            "DELETE FROM `$table`
              WHERE organization_id = :org AND encounter_id = :eid AND id = :id",
            ['org' => $this->scopeBinding(), 'eid' => $encounterId, 'id' => $id],
        ) > 0;
    }

    /**
     * @param array<string,mixed> $data
     * @param list<string>        $columns
     * @return array<string,mixed>
     */
    private function insertChild(string $table, array $data, array $columns): array
    {
        $row = array_only($data, $columns);
        $row['organization_id'] = $this->scopeBinding();
        $row['created_at']      = now();
        if (in_array($table, ['diagnoses', 'procedures', 'clinical_notes'], true)) {
            $row['updated_at'] = now();
        }

        $names        = array_keys($row);
        $placeholders = array_map(static fn(string $c): string => ':' . $c, $names);

        Database::statement(
            "INSERT INTO `$table` (" . implode(', ', array_map(static fn($c) => "`$c`", $names)) . ')'
            . ' VALUES (' . implode(', ', $placeholders) . ')',
            $row,
        );

        $id = Database::lastInsertId();

        return Database::selectOne("SELECT * FROM `$table` WHERE id = :id", ['id' => $id]) ?? $row;
    }
}
