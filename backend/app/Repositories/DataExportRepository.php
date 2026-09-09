<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Repository;
use App\Models\Patient;

/**
 * Every read behind a patient's data export (§16), moved out of
 * DataExportService per §18.
 *
 * Worth keeping in one class for the same reason as AiAssistantRepository: this
 * is the exact list of what leaves the building when somebody exercises their
 * right of access. "What is in the export?" should be answerable by reading one
 * file, not by tracing a closure through twenty queries.
 *
 * Everything is filtered to one patient inside one clinic.
 */
final class DataExportRepository extends Repository
{
    protected string $model = Patient::class;

    /** @return array<string,mixed>|null */
    public function patient(int $patientId): ?array
    {
        return $this->query(
            'SELECT p.*, u.email AS account_email, u.name AS account_name
               FROM patients p
               LEFT JOIN users u ON u.id = p.user_id
              WHERE p.organization_id = :org AND p.id = :id',
            ['org' => $this->scopeBinding(), 'id' => $patientId],
        )[0] ?? null;
    }

    /** @return array<string,mixed> */
    public function clinic(): array
    {
        return $this->query(
            'SELECT o.name, o.slug, c.name AS country, c.code AS country_code
               FROM organizations o
               JOIN countries c ON c.id = o.country_id
              WHERE o.id = :org',
            ['org' => $this->scopeBinding()],
        )[0] ?? [];
    }

    // ---------------------------------------------------------------
    // Standing record
    // ---------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function allergies(int $patientId): array
    {
        return $this->forPatient(
            'SELECT substance, reaction, severity, is_active, noted_on, created_at
               FROM allergies WHERE organization_id = :org AND patient_id = :pid ORDER BY id',
            $patientId,
        );
    }

    /** @return list<array<string,mixed>> */
    public function conditions(int $patientId): array
    {
        return $this->forPatient(
            'SELECT name, icd10_code, status, diagnosed_on, notes, created_at
               FROM medical_conditions
              WHERE organization_id = :org AND patient_id = :pid ORDER BY id',
            $patientId,
        );
    }

    /** @return list<array<string,mixed>> */
    public function appointments(int $patientId): array
    {
        return $this->forPatient(
            'SELECT a.scheduled_at, a.duration_minutes, a.type, a.status, a.reason,
                    a.cancelled_reason, u.name AS doctor
               FROM appointments a
               LEFT JOIN doctors d ON d.id = a.doctor_id
               LEFT JOIN users   u ON u.id = d.user_id
              WHERE a.organization_id = :org AND a.patient_id = :pid
              ORDER BY a.scheduled_at',
            $patientId,
        );
    }

    /**
     * Documents are listed, not included — the file bytes are fetched
     * separately, and a JSON export is the wrong place for a scan.
     *
     * @return list<array<string,mixed>>
     */
    public function documents(int $patientId): array
    {
        return $this->forPatient(
            'SELECT category, title, mime_type, size_bytes, visibility, created_at
               FROM medical_documents
              WHERE organization_id = :org AND patient_id = :pid ORDER BY created_at',
            $patientId,
        );
    }

    // ---------------------------------------------------------------
    // Visits
    // ---------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function encounters(int $patientId): array
    {
        return $this->forPatient(
            'SELECT e.id, e.encounter_no, e.type, e.status, e.chief_complaint, e.symptoms,
                    e.examination, e.followup_on, e.bp_systolic, e.bp_diastolic, e.pulse,
                    e.temperature_c, e.weight_kg, e.height_cm, e.created_at, e.completed_at,
                    u.name AS doctor
               FROM encounters e
               LEFT JOIN doctors d ON d.id = e.doctor_id
               LEFT JOIN users   u ON u.id = d.user_id
              WHERE e.organization_id = :org AND e.patient_id = :pid
              ORDER BY e.created_at',
            $patientId,
        );
    }

    /** @return list<array<string,mixed>> */
    public function diagnoses(int $encounterId): array
    {
        return $this->forEncounter(
            'SELECT description, icd10_code, type, notes, created_at
               FROM diagnoses WHERE organization_id = :org AND encounter_id = :eid',
            $encounterId,
        );
    }

    /** @return list<array<string,mixed>> */
    public function procedures(int $encounterId): array
    {
        return $this->forEncounter(
            'SELECT name, cpt_code, site, outcome, performed_at, created_at
               FROM procedures WHERE organization_id = :org AND encounter_id = :eid',
            $encounterId,
        );
    }

    /**
     * Only what the clinic released.
     *
     * An unapproved AI draft is not part of anybody's record yet, and §5 keeps
     * the clinician's working notes out of the patient's copy for the same
     * reason the app does.
     *
     * @return list<array<string,mixed>>
     */
    public function approvedNotes(int $encounterId): array
    {
        return $this->forEncounter(
            'SELECT type, body, approved_at, created_at
               FROM clinical_notes
              WHERE organization_id = :org AND encounter_id = :eid
                AND approved_at IS NOT NULL',
            $encounterId,
        );
    }

    // ---------------------------------------------------------------
    // Prescriptions and labs
    // ---------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function prescriptions(int $patientId): array
    {
        return $this->forPatient(
            'SELECT id, prescription_no, status, general_advice, issued_at, created_at
               FROM prescriptions
              WHERE organization_id = :org AND patient_id = :pid
              ORDER BY created_at',
            $patientId,
        );
    }

    /** @return list<array<string,mixed>> */
    public function prescriptionItems(int $prescriptionId): array
    {
        return $this->query(
            'SELECT medication_name, dosage, frequency, duration, instructions
               FROM prescription_items
              WHERE organization_id = :org AND prescription_id = :rid
              ORDER BY sort_order, id',
            ['org' => $this->scopeBinding(), 'rid' => $prescriptionId],
        );
    }

    /** @return list<array<string,mixed>> */
    public function labOrders(int $patientId): array
    {
        return $this->forPatient(
            'SELECT id, order_no, status, priority, clinical_notes, ordered_at, completed_at
               FROM lab_orders
              WHERE organization_id = :org AND patient_id = :pid
              ORDER BY created_at',
            $patientId,
        );
    }

    /** @return list<array<string,mixed>> */
    public function labResults(int $labOrderId): array
    {
        return $this->query(
            'SELECT test_name, value, unit, reference_range, flag, comments, reported_at
               FROM lab_results
              WHERE organization_id = :org AND lab_order_id = :lid
              ORDER BY id',
            ['org' => $this->scopeBinding(), 'lid' => $labOrderId],
        );
    }

    // ---------------------------------------------------------------
    // Money
    // ---------------------------------------------------------------

    /** Drafts excluded — a draft was never a bill the patient was shown. @return list<array<string,mixed>> */
    public function invoices(int $patientId): array
    {
        return $this->forPatient(
            'SELECT id, invoice_no, status, currency_code, subtotal, tax_total, discount_total,
                    grand_total, paid_total, issue_date, due_date, created_at
               FROM invoices
              WHERE organization_id = :org AND patient_id = :pid AND status <> \'draft\'
              ORDER BY created_at',
            $patientId,
        );
    }

    /** @return list<array<string,mixed>> */
    public function invoiceItems(int $invoiceId): array
    {
        return $this->query(
            'SELECT description, service_code, quantity, unit_price, discount_amount,
                    tax_amount, line_total
               FROM invoice_items
              WHERE organization_id = :org AND invoice_id = :iid
              ORDER BY sort_order, id',
            ['org' => $this->scopeBinding(), 'iid' => $invoiceId],
        );
    }

    /** @return list<array<string,mixed>> */
    public function invoicePayments(int $invoiceId): array
    {
        return $this->query(
            'SELECT receipt_no, method, status, amount, currency_code, paid_at
               FROM payments
              WHERE organization_id = :org AND invoice_id = :iid
              ORDER BY paid_at',
            ['org' => $this->scopeBinding(), 'iid' => $invoiceId],
        );
    }

    /** @return list<array<string,mixed>> */
    public function insurancePolicies(int $patientId): array
    {
        return $this->forPatient(
            'SELECT ip.policy_number, ip.member_id, ip.coverage_type, ip.coverage_amount,
                    ip.coverage_used, ip.valid_from, ip.valid_to, ip.status,
                    prov.name AS provider
               FROM insurance_policies ip
               LEFT JOIN insurance_providers prov ON prov.id = ip.insurance_provider_id
              WHERE ip.organization_id = :org AND ip.patient_id = :pid
              ORDER BY ip.id',
            $patientId,
        );
    }

    /** @return list<array<string,mixed>> */
    public function claims(int $patientId): array
    {
        return $this->forPatient(
            'SELECT claim_no, status, claimed_amount, approved_amount, paid_amount,
                    rejection_reason, submitted_at, decided_at
               FROM claims
              WHERE organization_id = :org AND patient_id = :pid
              ORDER BY created_at',
            $patientId,
        );
    }

    // ---------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    private function forPatient(string $sql, int $patientId): array
    {
        return $this->query($sql, ['org' => $this->scopeBinding(), 'pid' => $patientId]);
    }

    /** @return list<array<string,mixed>> */
    private function forEncounter(string $sql, int $encounterId): array
    {
        return $this->query($sql, ['org' => $this->scopeBinding(), 'eid' => $encounterId]);
    }
}
