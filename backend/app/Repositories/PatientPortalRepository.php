<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Repository;
use App\Models\Patient;

/**
 * The patient app's reads (§3), moved out of PatientPortalService per §18.
 *
 * Every method here takes the patient id as an argument and filters on it.
 * That is not a convenience — it is the guarantee: the portal has no endpoint
 * that accepts a patient id from the caller, so the only id that can reach
 * these queries is the one the session resolved. Keeping the WHERE clause in
 * one file makes that reviewable.
 *
 * The model is Patient because that is what these reads are *about*, even
 * where the row comes from another table; the joins are all "…for this
 * patient".
 */
final class PatientPortalRepository extends Repository
{
    protected string $model = Patient::class;

    // ---------------------------------------------------------------
    // Dashboard (§3)
    // ---------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function upcomingAppointments(int $patientId, int $limit = 5): array
    {
        return $this->query(
            'SELECT a.id, a.scheduled_at, a.duration_minutes, a.status, a.reason, a.type,
                    u.name AS doctor_name, d.specialty, d.room
               FROM appointments a
               JOIN doctors d ON d.id = a.doctor_id
               JOIN users   u ON u.id = d.user_id
              WHERE a.organization_id = :org AND a.patient_id = :pid
                AND a.scheduled_at >= UTC_TIMESTAMP()
                AND a.status IN (\'booked\', \'confirmed\', \'arrived\')
              ORDER BY a.scheduled_at
              LIMIT ' . $this->limit($limit),
            $this->args($patientId),
        );
    }

    /** Bills that still want paying. @return list<array<string,mixed>> */
    public function openBills(int $patientId, int $limit = 10): array
    {
        return $this->query(
            'SELECT id, invoice_no, currency_code, grand_total, paid_total,
                    balance_due, status, due_date, issue_date
               FROM invoices
              WHERE organization_id = :org AND patient_id = :pid
                AND status IN (\'issued\', \'partially_paid\', \'overdue\')
              ORDER BY due_date, created_at
              LIMIT ' . $this->limit($limit),
            $this->args($patientId),
        );
    }

    /** @return list<array<string,mixed>> */
    public function issuedPrescriptions(int $patientId, int $limit = 5): array
    {
        return $this->query(
            'SELECT rx.id, rx.prescription_no, rx.status, rx.issued_at, rx.created_at,
                    u.name AS doctor_name,
                    (SELECT COUNT(*) FROM prescription_items i
                      WHERE i.prescription_id = rx.id) AS item_count
               FROM prescriptions rx
               JOIN doctors d ON d.id = rx.doctor_id
               JOIN users   u ON u.id = d.user_id
              WHERE rx.organization_id = :org AND rx.patient_id = :pid
                AND rx.status = \'issued\'
              ORDER BY rx.issued_at DESC
              LIMIT ' . $this->limit($limit),
            $this->args($patientId),
        );
    }

    /** §3 "treatment reminders": follow-ups the doctor asked for. @return list<array<string,mixed>> */
    public function upcomingFollowUps(int $patientId, int $limit = 5): array
    {
        return $this->query(
            'SELECT e.id, e.encounter_no, e.followup_on, u.name AS doctor_name
               FROM encounters e
               JOIN doctors d ON d.id = e.doctor_id
               JOIN users   u ON u.id = d.user_id
              WHERE e.organization_id = :org AND e.patient_id = :pid
                AND e.followup_on IS NOT NULL
                AND e.followup_on >= CURDATE()
              ORDER BY e.followup_on
              LIMIT ' . $this->limit($limit),
            $this->args($patientId),
        );
    }

    /**
     * How many rows of one kind this patient has.
     *
     * The table name is never user input — every caller passes a literal — and
     * the predicate is a constant in the calling service, so there is nothing
     * here for a request to reach.
     */
    public function countFor(int $patientId, string $table, string $predicate): int
    {
        $row = $this->query(
            "SELECT COUNT(*) AS c FROM $table
              WHERE organization_id = :org AND patient_id = :pid AND $predicate",
            $this->args($patientId),
        )[0] ?? [];

        return (int) ($row['c'] ?? 0);
    }

    public function lastVisitDate(int $patientId): ?string
    {
        $row = $this->query(
            'SELECT MAX(COALESCE(completed_at, started_at)) AS last
               FROM encounters
              WHERE organization_id = :org AND patient_id = :pid AND status = \'completed\'',
            $this->args($patientId),
        )[0] ?? [];

        return $row['last'] ?? null;
    }

    /** @return list<array<string,mixed>> */
    public function insurancePolicies(int $patientId): array
    {
        return $this->query(
            'SELECT ip.*, prov.name AS provider_name
               FROM insurance_policies ip
               JOIN insurance_providers prov ON prov.id = ip.insurance_provider_id
              WHERE ip.organization_id = :org AND ip.patient_id = :pid
              ORDER BY ip.is_primary DESC',
            $this->args($patientId),
        );
    }

    // ---------------------------------------------------------------
    // Appointments
    // ---------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function appointments(int $patientId, ?string $scope = null): array
    {
        $where = ['a.organization_id = :org', 'a.patient_id = :pid'];

        if ($scope === 'upcoming') {
            $where[] = 'a.scheduled_at >= UTC_TIMESTAMP()';
            $where[] = "a.status IN ('booked','confirmed','arrived','in_consultation')";
        } elseif ($scope === 'past') {
            $where[] = "(a.scheduled_at < UTC_TIMESTAMP() OR a.status IN ('completed','cancelled','no_show'))";
        }

        return $this->query(
            'SELECT a.*, u.name AS doctor_name, d.specialty, d.room,
                    e.id AS encounter_id, e.status AS encounter_status
               FROM appointments a
               JOIN doctors d ON d.id = a.doctor_id
               JOIN users   u ON u.id = d.user_id
               LEFT JOIN encounters e ON e.appointment_id = a.id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY a.scheduled_at DESC
              LIMIT 100',
            $this->args($patientId),
        );
    }

    /**
     * One appointment, but only if it is this patient's.
     *
     * Ownership is part of the query rather than checked after the read, so
     * there is no window in which somebody else's row is in a variable.
     *
     * @return array<string,mixed>|null
     */
    public function ownAppointment(int $patientId, int $appointmentId): ?array
    {
        return $this->query(
            'SELECT * FROM appointments
              WHERE organization_id = :org AND id = :id AND patient_id = :pid',
            $this->args($patientId) + ['id' => $appointmentId],
        )[0] ?? null;
    }

    // ---------------------------------------------------------------
    // Choosing a doctor (§3)
    // ---------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function bookableDoctors(
        ?string $search = null,
        ?string $specialty = null,
        ?string $location = null,
    ): array {
        $where    = ['d.organization_id = :org', 'd.is_accepting = 1'];
        $bindings = ['org' => $this->scopeBinding()];

        // Free text is the forgiving one — it matches a half-remembered name or
        // a specialty the patient spells their own way. The two named filters
        // below come from lists the patient picked from, so they match exactly.
        if ($search !== null && trim($search) !== '') {
            $where[]       = '(u.name LIKE :q OR d.specialty LIKE :q OR d.location LIKE :q)';
            $bindings['q'] = '%' . trim($search) . '%';
        }
        if ($specialty !== null && trim($specialty) !== '') {
            $where[]          = 'd.specialty = :spec';
            $bindings['spec'] = trim($specialty);
        }
        if ($location !== null && trim($location) !== '') {
            $where[]         = 'd.location = :loc';
            $bindings['loc'] = trim($location);
        }

        return $this->query(
            'SELECT d.id, u.name AS doctor_name, d.specialty, d.location,
                    d.qualification, d.experience_years, d.consultation_fee,
                    d.followup_fee, d.room, d.slot_minutes, d.bio
               FROM doctors d
               JOIN users u ON u.id = d.user_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY d.specialty, u.name',
            $bindings,
        );
    }

    /**
     * The specialties and locations this clinic actually has doctors in.
     *
     * @return list<array<string,mixed>>
     */
    public function doctorFilterValues(): array
    {
        return $this->query(
            'SELECT DISTINCT d.specialty, d.location
               FROM doctors d
              WHERE d.organization_id = :org
                AND d.is_accepting = 1',
            ['org' => $this->scopeBinding()],
        );
    }

    // ---------------------------------------------------------------
    // Records (§3)
    // ---------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function completedEncounters(int $patientId, int $limit = 50): array
    {
        return $this->query(
            'SELECT e.id, e.encounter_no, e.type, e.status, e.chief_complaint,
                    e.symptoms, e.examination, e.followup_on,
                    e.bp_systolic, e.bp_diastolic, e.pulse, e.temperature_c,
                    e.weight_kg, e.height_cm,
                    e.created_at, e.completed_at,
                    u.name AS doctor_name, d.specialty
               FROM encounters e
               JOIN doctors d ON d.id = e.doctor_id
               JOIN users   u ON u.id = d.user_id
              WHERE e.organization_id = :org AND e.patient_id = :pid
                AND e.status = \'completed\'
              ORDER BY e.created_at DESC
              LIMIT ' . $this->limit($limit),
            $this->args($patientId),
        );
    }

    /** @return list<array<string,mixed>> */
    public function encounterDiagnoses(int $encounterId): array
    {
        return $this->query(
            'SELECT description, icd10_code, type FROM diagnoses
              WHERE organization_id = :org AND encounter_id = :eid',
            ['org' => $this->scopeBinding(), 'eid' => $encounterId],
        );
    }

    /** @return list<array<string,mixed>> */
    public function encounterProcedures(int $encounterId): array
    {
        return $this->query(
            'SELECT name, site, performed_at FROM procedures
              WHERE organization_id = :org AND encounter_id = :eid',
            ['org' => $this->scopeBinding(), 'eid' => $encounterId],
        );
    }

    // ---------------------------------------------------------------
    // Money (§7)
    // ---------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function paymentHistory(int $patientId, int $limit = 50): array
    {
        return $this->query(
            'SELECT p.receipt_no, p.method, p.amount, p.currency_code, p.status,
                    p.paid_at, p.created_at, i.invoice_no
               FROM payments p
               JOIN invoices i ON i.id = p.invoice_id
              WHERE p.organization_id = :org AND p.patient_id = :pid
              ORDER BY p.created_at DESC
              LIMIT ' . $this->limit($limit),
            $this->args($patientId),
        );
    }

    /** @return list<array<string,mixed>> */
    public function refundHistory(int $patientId, int $limit = 20): array
    {
        return $this->query(
            'SELECT r.amount, r.currency_code, r.status, r.reason, r.refunded_at,
                    i.invoice_no
               FROM refunds r
               JOIN invoices i ON i.id = r.invoice_id
              WHERE r.organization_id = :org AND i.patient_id = :pid
              ORDER BY r.created_at DESC
              LIMIT ' . $this->limit($limit),
            $this->args($patientId),
        );
    }

    /**
     * A payment already recorded against this gateway reference, if any.
     *
     * Capture can be called twice — a retried request, a double tap. The
     * honest answer to the second one is the payment that already exists, so
     * the caller needs to be able to ask.
     *
     * @return array<string,mixed>|null
     */
    public function findByGatewayRef(string $gateway, string $reference): ?array
    {
        return $this->query(
            'SELECT p.* FROM payments p
              WHERE p.organization_id = :org AND p.gateway = :gw AND p.gateway_ref = :ref',
            ['org' => $this->scopeBinding(), 'gw' => $gateway, 'ref' => $reference],
        )[0] ?? null;
    }

    // ---------------------------------------------------------------

    /** @return array{org:int, pid:int} */
    private function args(int $patientId): array
    {
        return ['org' => $this->scopeBinding(), 'pid' => $patientId];
    }

    /** MySQL will not take a placeholder in LIMIT under emulated prepares. */
    private function limit(int $limit): string
    {
        return (string) max(1, min(500, $limit));
    }
}
