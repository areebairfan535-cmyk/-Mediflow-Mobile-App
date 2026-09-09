<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Repository;
use App\Models\Patient;

/**
 * What the AI assistant is allowed to read (§28), moved out of
 * AiAssistantService per §18.
 *
 * Worth having as its own class rather than scattering these across the
 * clinical repositories: this is the exact list of what leaves the clinic in a
 * prompt. If someone asks "what does the model see about a patient?", the
 * answer is the methods below and nothing else.
 *
 * Every one is tenant-scoped, and the search term reaches SQL only as a bound
 * parameter — never as text.
 */
final class AiAssistantRepository extends Repository
{
    protected string $model = Patient::class;

    // ---------------------------------------------------------------
    // Clinical context for a prompt
    // ---------------------------------------------------------------

    /** @return array<string,mixed>|null */
    public function patientWithAge(int $patientId): ?array
    {
        return $this->query(
            'SELECT p.*, TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) AS age
               FROM patients p
              WHERE p.organization_id = :org AND p.id = :id',
            ['org' => $this->scopeBinding(), 'id' => $patientId],
        )[0] ?? null;
    }

    /** @return list<array<string,mixed>> */
    public function activeAllergies(int $patientId): array
    {
        return $this->query(
            'SELECT substance, severity, reaction FROM allergies
              WHERE organization_id = :org AND patient_id = :pid AND is_active = 1',
            $this->args($patientId),
        );
    }

    /** @return list<array<string,mixed>> */
    public function conditions(int $patientId): array
    {
        return $this->query(
            'SELECT name, status FROM medical_conditions
              WHERE organization_id = :org AND patient_id = :pid',
            $this->args($patientId),
        );
    }

    /** Recent completed visits, each with its diagnoses rolled up. @return list<array<string,mixed>> */
    public function recentVisits(int $patientId, int $limit = 5): array
    {
        return $this->query(
            "SELECT e.encounter_no, e.chief_complaint, e.completed_at,
                    GROUP_CONCAT(d.description SEPARATOR ', ') AS diagnoses
               FROM encounters e
               LEFT JOIN diagnoses d ON d.encounter_id = e.id
              WHERE e.organization_id = :org AND e.patient_id = :pid
                AND e.status = 'completed'
              GROUP BY e.id
              ORDER BY e.completed_at DESC
              LIMIT " . $this->cap($limit),
            $this->args($patientId),
        );
    }

    /** @return list<array<string,mixed>> */
    public function currentMedicines(int $patientId, int $limit = 12): array
    {
        return $this->query(
            "SELECT pi.medication_name, pi.dosage, pi.frequency, pi.duration
               FROM prescription_items pi
               JOIN prescriptions p ON p.id = pi.prescription_id
              WHERE p.organization_id = :org AND p.patient_id = :pid
                AND p.status = 'issued'
              ORDER BY p.issued_at DESC
              LIMIT " . $this->cap($limit),
            $this->args($patientId),
        );
    }

    // ---------------------------------------------------------------
    // Claims (§15, §28)
    // ---------------------------------------------------------------

    /**
     * How this insurer has rejected claims before.
     *
     * Past rejections from the same provider are the most useful signal
     * available for "will this one come back", so the model gets them.
     *
     * @return list<array<string,mixed>>
     */
    public function insurerRejectionHistory(int $policyId, int $limit = 10): array
    {
        return $this->query(
            'SELECT c.rejection_code, c.rejection_reason, COUNT(*) AS times
               FROM claims c
               JOIN insurance_policies ip ON ip.id = c.insurance_policy_id
              WHERE c.organization_id = :org
                AND ip.insurance_provider_id = (
                    SELECT insurance_provider_id FROM insurance_policies WHERE id = :pid
                )
                AND c.status IN (\'rejected\', \'partially_approved\')
                AND c.rejection_reason IS NOT NULL
              GROUP BY c.rejection_code, c.rejection_reason
              ORDER BY times DESC
              LIMIT ' . $this->cap($limit),
            ['org' => $this->scopeBinding(), 'pid' => $policyId],
        );
    }

    /**
     * File the model's opinion against a claim.
     *
     * Advisory only — the claim's own status is deliberately untouched. A
     * score is something for a human to read, not a decision.
     *
     * @param list<string> $missing
     */
    public function saveClaimRiskScore(int $claimId, int $score, array $missing): void
    {
        Database::statement(
            'UPDATE claims
                SET ai_risk_score = :score, ai_missing_items = :missing, updated_at = :now
              WHERE organization_id = :org AND id = :id',
            [
                'score'   => $score,
                'missing' => json_encode($missing, JSON_UNESCAPED_UNICODE),
                'now'     => now(),
                'org'     => $this->scopeBinding(),
                'id'      => $claimId,
            ],
        );
    }

    // ---------------------------------------------------------------
    // Natural-language search (§28)
    // ---------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function searchPatients(string $like, int $limit): array
    {
        return $this->query(
            "SELECT id, mrn, first_name, last_name, phone, email, status
               FROM patients
              WHERE organization_id = :org
                AND (CONCAT(first_name, ' ', last_name) LIKE :q
                     OR mrn LIKE :q2 OR phone LIKE :q3 OR email LIKE :q4)
              ORDER BY first_name
              LIMIT " . $this->cap($limit),
            ['org' => $this->scopeBinding(), 'q' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like],
        );
    }

    /** @return list<array<string,mixed>> */
    public function searchInvoices(string $like, int $limit): array
    {
        return $this->query(
            "SELECT i.id, i.invoice_no, i.status, i.grand_total, i.balance_due,
                    i.currency_code, i.patient_id,
                    CONCAT(p.first_name, ' ', p.last_name) AS patient_name
               FROM invoices i
               JOIN patients p ON p.id = i.patient_id
              WHERE i.organization_id = :org AND i.invoice_no LIKE :q
              ORDER BY i.id DESC
              LIMIT " . $this->cap($limit),
            ['org' => $this->scopeBinding(), 'q' => $like],
        );
    }

    /** @return list<array<string,mixed>> */
    public function searchPrescriptions(string $like, int $limit): array
    {
        return $this->query(
            "SELECT rx.id, rx.prescription_no, rx.status, rx.patient_id,
                    CONCAT(p.first_name, ' ', p.last_name) AS patient_name
               FROM prescriptions rx
               JOIN patients p ON p.id = rx.patient_id
              WHERE rx.organization_id = :org AND rx.prescription_no LIKE :q
              ORDER BY rx.id DESC
              LIMIT " . $this->cap($limit),
            ['org' => $this->scopeBinding(), 'q' => $like],
        );
    }

    /**
     * "Who have I diagnosed with this?" — a question the patient list cannot
     * answer, and the reason this search is worth having at all.
     *
     * @return list<array<string,mixed>>
     */
    public function searchDiagnoses(string $like, int $limit): array
    {
        return $this->query(
            "SELECT d.description, d.icd10_code, d.encounter_id, e.patient_id,
                    e.encounter_no, e.completed_at,
                    CONCAT(p.first_name, ' ', p.last_name) AS patient_name
               FROM diagnoses d
               JOIN encounters e ON e.id = d.encounter_id
               JOIN patients p   ON p.id = e.patient_id
              WHERE d.organization_id = :org
                AND (d.description LIKE :q OR d.icd10_code LIKE :q2)
              ORDER BY e.completed_at DESC
              LIMIT " . $this->cap($limit),
            ['org' => $this->scopeBinding(), 'q' => $like, 'q2' => $like],
        );
    }

    /** @return list<array<string,mixed>> */
    public function searchMedicines(string $like, int $limit): array
    {
        return $this->query(
            "SELECT pi.medication_name, pi.dosage, rx.prescription_no, rx.id AS prescription_id,
                    rx.patient_id, CONCAT(p.first_name, ' ', p.last_name) AS patient_name
               FROM prescription_items pi
               JOIN prescriptions rx ON rx.id = pi.prescription_id
               JOIN patients p       ON p.id = rx.patient_id
              WHERE rx.organization_id = :org AND pi.medication_name LIKE :q
              ORDER BY rx.id DESC
              LIMIT " . $this->cap($limit),
            ['org' => $this->scopeBinding(), 'q' => $like],
        );
    }

    // ---------------------------------------------------------------

    /** @return array{org:int, pid:int} */
    private function args(int $patientId): array
    {
        return ['org' => $this->scopeBinding(), 'pid' => $patientId];
    }

    /** MySQL will not take a placeholder in LIMIT under emulated prepares. */
    private function cap(int $limit): string
    {
        return (string) max(1, min(50, $limit));
    }
}
