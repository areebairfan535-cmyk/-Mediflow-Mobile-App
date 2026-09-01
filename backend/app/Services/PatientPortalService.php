<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ConflictException;
use App\Core\Database;
use App\Core\ForbiddenException;
use App\Core\NotFoundException;
use App\Core\Service;
use App\Repositories\ClinicalRepository;
use App\Repositories\InvoiceRepository;
use App\Repositories\PatientRepository;
use App\Repositories\PrescriptionRepository;
use App\Services\Billing\Money;

/**
 * Everything the patient mobile app reads (§3).
 *
 * ---------------------------------------------------------------------------
 * The patient NEVER sends their own patient_id.
 * ---------------------------------------------------------------------------
 * Every method resolves the record from the authenticated user
 * (patients.user_id). A patient_id in a request body would be a parameter an
 * attacker controls, and "show me chart 47" is exactly the request that must
 * not be answerable. `me()` is the only door in.
 *
 * The clinic-facing services enforce the same rule from the other side via
 * PatientService::assertMayAccess(); this class exists so the app never has to
 * guess an id at all.
 */
final class PatientPortalService extends Service
{
    private function patients(): PatientRepository
    {
        return (new PatientRepository())->forOrganization($this->requireOrganization());
    }

    /**
     * The patient record behind the signed-in account.
     *
     * @return array<string,mixed>
     */
    public function me(): array
    {
        $patient = $this->patients()->forUser((int) $this->actorId);

        if ($patient === null) {
            throw new ForbiddenException(
                'This account is not linked to a patient record at this clinic.'
            );
        }

        return $patient;
    }

    private function meId(): int
    {
        return (int) $this->me()['id'];
    }

    /**
     * §3 dashboard: upcoming appointments, outstanding bills, recent
     * prescriptions, medical alerts and a health summary — in one call,
     * because a phone on a slow connection should not make six.
     *
     * @return array<string,mixed>
     */
    public function dashboard(): array
    {
        $org       = $this->requireOrganization();
        $patient   = $this->patients()->withClinicalSummary($this->meId());
        $patientId = (int) $patient['id'];
        $args      = ['org' => $org, 'pid' => $patientId];

        $upcoming = Database::select(
            'SELECT a.id, a.scheduled_at, a.duration_minutes, a.status, a.reason, a.type,
                    u.name AS doctor_name, d.specialty, d.room
               FROM appointments a
               JOIN doctors d ON d.id = a.doctor_id
               JOIN users   u ON u.id = d.user_id
              WHERE a.organization_id = :org AND a.patient_id = :pid
                AND a.scheduled_at >= UTC_TIMESTAMP()
                AND a.status IN (\'booked\', \'confirmed\', \'arrived\')
              ORDER BY a.scheduled_at
              LIMIT 5',
            $args,
        );

        $bills = Database::select(
            'SELECT id, invoice_no, currency_code, grand_total, paid_total,
                    balance_due, status, due_date, issue_date
               FROM invoices
              WHERE organization_id = :org AND patient_id = :pid
                AND status IN (\'issued\', \'partially_paid\', \'overdue\')
              ORDER BY due_date, created_at
              LIMIT 10',
            $args,
        );

        $outstanding = Money::sum(array_column($bills, 'balance_due'));

        $prescriptions = Database::select(
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
              LIMIT 5',
            $args,
        );

        // §3 "treatment reminders": follow-ups the doctor asked for.
        $followUps = Database::select(
            'SELECT e.id, e.encounter_no, e.followup_on, u.name AS doctor_name
               FROM encounters e
               JOIN doctors d ON d.id = e.doctor_id
               JOIN users   u ON u.id = d.user_id
              WHERE e.organization_id = :org AND e.patient_id = :pid
                AND e.followup_on IS NOT NULL
                AND e.followup_on >= CURDATE()
              ORDER BY e.followup_on
              LIMIT 5',
            $args,
        );

        return [
            'patient' => [
                'id'          => $patientId,
                'mrn'         => $patient['mrn'],
                'name'        => $patient['first_name'] . ' ' . $patient['last_name'],
                'age'         => $patient['age'] ?? null,
                'gender'      => $patient['gender'],
                'blood_group' => $patient['blood_group'],
            ],
            // §3 "medical alerts" — allergies first, because they are the
            // thing that matters in an emergency.
            'alerts' => [
                'allergies'  => $patient['allergies'],
                'conditions' => $patient['conditions'],
            ],
            'upcoming_appointments' => $upcoming,
            'outstanding' => [
                'total'    => Money::round($outstanding),
                'currency' => $bills[0]['currency_code'] ?? null,
                'invoices' => $bills,
            ],
            'recent_prescriptions' => $prescriptions,
            'follow_ups'           => $followUps,

            /**
             * §3's "health summary": the handful of numbers that answer
             * "where do I stand with this clinic" without reading four tabs.
             *
             * Counts, not content — the content already has its own screens,
             * and a summary that restates them is just a fifth place for them
             * to disagree.
             */
            'health_summary' => [
                'visits'             => $this->countFor('encounters', "status = 'completed'"),
                'last_visit'         => $this->lastVisitDate(),
                'active_conditions'  => count(array_filter(
                    $patient['conditions'] ?? [],
                    static fn(array $c): bool => in_array($c['status'] ?? '', ['active', 'chronic'], true),
                )),
                'allergies'          => count($patient['allergies'] ?? []),
                'prescriptions'      => $this->countFor('prescriptions', "status = 'issued'"),
                'lab_orders'         => $this->countFor('lab_orders', '1 = 1'),
                'upcoming_visits'    => count($upcoming),
                'unpaid_invoices'    => count($bills),
            ],
            'unread_notifications' => (new NotificationService(
                $this->organizationId,
                $this->actorId,
            ))->unreadCount((int) $this->actorId),
        ];
    }

    /** @return array<string,mixed> */
    public function profile(): array
    {
        $patient = $this->patients()->withClinicalSummary($this->meId());

        $patient['insurance'] = Database::select(
            'SELECT ip.*, prov.name AS provider_name
               FROM insurance_policies ip
               JOIN insurance_providers prov ON prov.id = ip.insurance_provider_id
              WHERE ip.organization_id = :org AND ip.patient_id = :pid
              ORDER BY ip.is_primary DESC',
            ['org' => $this->requireOrganization(), 'pid' => (int) $patient['id']],
        );

        return $patient;
    }

    /**
     * A patient may correct their own contact details — never their clinical
     * record, and never their MRN.
     *
     * @param array<string,mixed> $data
     */
    public function updateProfile(array $data): array
    {
        // What a patient may change about themselves: who they are and how to
        // reach them. The allow-list is the control — everything absent from
        // it is refused whatever the client sends.
        //
        // Still NOT here, deliberately: blood group, allergies, medical
        // conditions, insurance, the MRN and the record's status.
        //
        // Blood group looks like a personal detail and is not one — it is a
        // test result, and it is read off this screen in an emergency. The
        // same goes for allergies: edited from a phone, a wrong one silently
        // disarms the warning a doctor gets when prescribing. Those are the
        // clinic's to record, and the clinic app is where they are corrected.
        $allowed = array_only($data, [
            'first_name', 'last_name', 'date_of_birth', 'gender',
            'phone', 'email', 'address', 'city',
            'emergency_name', 'emergency_phone', 'emergency_relation',
        ]);

        if ($allowed === []) {
            return $this->profile();
        }

        $this->patients()->update($this->meId(), $allowed + ['updated_by' => $this->actorId]);

        return $this->profile();
    }

    /** @return list<array<string,mixed>> */
    public function appointments(?string $scope = null): array
    {
        $where = ['a.organization_id = :org', 'a.patient_id = :pid'];

        if ($scope === 'upcoming') {
            $where[] = 'a.scheduled_at >= UTC_TIMESTAMP()';
            $where[] = "a.status IN ('booked','confirmed','arrived','in_consultation')";
        } elseif ($scope === 'past') {
            $where[] = "(a.scheduled_at < UTC_TIMESTAMP() OR a.status IN ('completed','cancelled','no_show'))";
        }

        return Database::select(
            'SELECT a.*, u.name AS doctor_name, d.specialty, d.room,
                    e.id AS encounter_id, e.status AS encounter_status
               FROM appointments a
               JOIN doctors d ON d.id = a.doctor_id
               JOIN users   u ON u.id = d.user_id
               LEFT JOIN encounters e ON e.appointment_id = a.id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY a.scheduled_at DESC
              LIMIT 100',
            ['org' => $this->requireOrganization(), 'pid' => $this->meId()],
        );
    }

    /**
     * Cancel one's own appointment.
     *
     * A patient may cancel and nothing else — no rescheduling into a slot the
     * clinic has not offered, no status changes. Ownership is re-checked here
     * rather than trusted from the id.
     */
    /**
     * How many rows of one kind this patient has.
     *
     * The table name is never user input — every caller passes a literal — and
     * the predicate is a constant in this file, so there is nothing here for a
     * request to reach.
     */
    private function countFor(string $table, string $predicate): int
    {
        $row = Database::selectOne(
            "SELECT COUNT(*) AS c FROM $table
              WHERE organization_id = :org AND patient_id = :pid AND $predicate",
            ['org' => $this->requireOrganization(), 'pid' => $this->meId()],
        );

        return (int) ($row['c'] ?? 0);
    }

    private function lastVisitDate(): ?string
    {
        $row = Database::selectOne(
            'SELECT MAX(COALESCE(completed_at, started_at)) AS last
               FROM encounters
              WHERE organization_id = :org AND patient_id = :pid AND status = \'completed\'',
            ['org' => $this->requireOrganization(), 'pid' => $this->meId()],
        );

        return $row['last'] ?? null;
    }

    /**
     * Doctors a patient can book with (§3: search, specialty, location).
     *
     * Deliberately narrower than the clinic's own doctor list: fees and the
     * consulting room are what a patient needs to choose; who a doctor's user
     * account is, and what they earn, are not.
     *
     * @return list<array<string,mixed>>
     */
    public function bookableDoctors(?string $search = null, ?string $specialty = null): array
    {
        $where    = ['d.organization_id = :org', 'd.is_accepting = 1'];
        $bindings = ['org' => $this->requireOrganization()];

        if ($search !== null && trim($search) !== '') {
            $where[]             = '(u.name LIKE :q OR d.specialty LIKE :q)';
            $bindings['q']       = '%' . trim($search) . '%';
        }
        if ($specialty !== null && trim($specialty) !== '') {
            $where[]              = 'd.specialty = :spec';
            $bindings['spec']     = trim($specialty);
        }

        return Database::select(
            'SELECT d.id, u.name AS doctor_name, d.specialty, d.qualification,
                    d.experience_years, d.consultation_fee, d.followup_fee,
                    d.room, d.slot_minutes, d.bio
               FROM doctors d
               JOIN users u ON u.id = d.user_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY d.specialty, u.name',
            $bindings,
        );
    }

    /**
     * Free slots for one doctor on one day.
     *
     * Computed by the clinic's own AppointmentService, so a patient can never
     * be offered a slot the front desk would refuse — one timetable, not two.
     *
     * @return array<string,mixed>
     */
    public function availableSlots(int $doctorId, string $date): array
    {
        return (new AppointmentService($this->organizationId, $this->actorId))
            ->availableSlots($doctorId, $date);
    }

    /**
     * The patient books their own appointment (§3).
     *
     * `patient_id` is taken from the signed-in account and never from the
     * request: a patient booking "for" somebody else is how one account starts
     * writing into another patient's calendar.
     *
     * Everything after that — working hours, double-booking, overlap, the plan's
     * monthly appointment limit — is AppointmentService's, unchanged.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function book(array $data): array
    {
        $appointments = new AppointmentService($this->organizationId, $this->actorId);

        return $appointments->book([
            'patient_id'       => $this->meId(),
            'doctor_id'        => (int) $data['doctor_id'],
            'scheduled_at'     => (string) $data['scheduled_at'],
            'duration_minutes' => $data['duration_minutes'] ?? null,
            'reason'           => $data['reason'] ?? null,
            'type'             => $data['type'] ?? 'consultation',
        ]);
    }

    /**
     * Move an appointment the patient already has.
     *
     * Only one they own, and only one that has not started: a visit that is
     * over, cancelled, or already in the consulting room is not a booking any
     * more, and rescheduling it would rewrite history.
     *
     * @return array<string,mixed>
     */
    public function reschedule(int $appointmentId, string $startsAt, ?string $reason = null): array
    {
        $appointment = Database::selectOne(
            'SELECT * FROM appointments
              WHERE organization_id = :org AND id = :id AND patient_id = :pid',
            ['org' => $this->requireOrganization(), 'id' => $appointmentId, 'pid' => $this->meId()],
        );

        if ($appointment === null) {
            throw new NotFoundException('Appointment not found');
        }

        if (!in_array($appointment['status'], ['booked', 'confirmed'], true)) {
            throw new ConflictException(
                'This appointment is ' . str_replace('_', ' ', (string) $appointment['status'])
                . ' and can no longer be moved. Please call the clinic.'
            );
        }

        return (new AppointmentService($this->organizationId, $this->actorId))
            ->reschedule($appointmentId, $startsAt, null, $reason ?? 'Moved by the patient');
    }

    public function cancelAppointment(int $appointmentId, ?string $reason): array
    {
        $patientId   = $this->meId();
        $appointment = Database::selectOne(
            'SELECT * FROM appointments
              WHERE organization_id = :org AND id = :id AND patient_id = :pid',
            ['org' => $this->requireOrganization(), 'id' => $appointmentId, 'pid' => $patientId],
        );

        if ($appointment === null) {
            throw new NotFoundException('Appointment not found');
        }

        // Delegate to the same service the clinic uses, so the legal-transition
        // rules apply identically no matter who cancels.
        return (new AppointmentService($this->organizationId, $this->actorId))
            ->changeStatus($appointmentId, 'cancelled', $reason ?? 'Cancelled by patient')['after'];
    }

    /**
     * §3 medical records: visit history with what was recorded at each.
     *
     * @return list<array<string,mixed>>
     */
    public function records(): array
    {
        $org       = $this->requireOrganization();
        $patientId = $this->meId();

        $encounters = Database::select(
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
              LIMIT 50',
            ['org' => $org, 'pid' => $patientId],
        );

        foreach ($encounters as $i => $encounter) {
            $args = ['org' => $org, 'eid' => (int) $encounter['id']];

            $encounters[$i]['diagnoses'] = Database::select(
                'SELECT description, icd10_code, type FROM diagnoses
                  WHERE organization_id = :org AND encounter_id = :eid',
                $args,
            );
            $encounters[$i]['procedures'] = Database::select(
                'SELECT name, site, performed_at FROM procedures
                  WHERE organization_id = :org AND encounter_id = :eid',
                $args,
            );
            // Clinical notes are deliberately NOT exposed: §5 treats them as
            // the clinician's working record, and releasing them is a decision
            // the clinic makes per document, via medical_documents.
        }

        return $encounters;
    }

    /** @return list<array<string,mixed>> */
    public function prescriptions(): array
    {
        $rows = (new PrescriptionRepository())
            ->forOrganization($this->requireOrganization())
            ->forPatient($this->meId());

        // A draft prescription is not yet the patient's document.
        return array_values(array_filter(
            $rows,
            static fn(array $rx): bool => $rx['status'] === 'issued',
        ));
    }

    /** @return list<array<string,mixed>> */
    public function labResults(): array
    {
        return array_values(array_filter(
            (new ClinicalRepository())
                ->forOrganization($this->requireOrganization())
                ->labOrders(['patient_id' => $this->meId()]),
            static fn(array $order): bool => $order['status'] === 'completed',
        ));
    }

    /** @return list<array<string,mixed>> Only what the clinic marked patient-visible. */
    public function documents(): array
    {
        return (new ClinicalRepository())
            ->forOrganization($this->requireOrganization())
            ->documents($this->meId(), true);
    }

    /**
     * §3 billing: invoices, balances and payment history.
     *
     * @return array<string,mixed>
     */
    public function bills(): array
    {
        $org       = $this->requireOrganization();
        $patientId = $this->meId();
        $invoices  = (new InvoiceRepository())->forOrganization($org)
            ->search(['patient_id' => $patientId], 1, 100);

        // Drafts are the clinic's working documents, not the patient's bills.
        $visible = array_values(array_filter(
            $invoices['data'],
            static fn(array $i): bool => $i['status'] !== 'draft',
        ));

        $outstanding = Money::sum(array_map(
            static fn(array $i): string => in_array($i['status'], ['issued', 'partially_paid', 'overdue'], true)
                ? (string) $i['balance_due']
                : '0',
            $visible,
        ));

        $payments = Database::select(
            'SELECT p.receipt_no, p.method, p.amount, p.currency_code, p.status,
                    p.paid_at, p.created_at, i.invoice_no
               FROM payments p
               JOIN invoices i ON i.id = p.invoice_id
              WHERE p.organization_id = :org AND p.patient_id = :pid
              ORDER BY p.created_at DESC
              LIMIT 50',
            ['org' => $org, 'pid' => $patientId],
        );

        $refunds = Database::select(
            'SELECT r.amount, r.currency_code, r.status, r.reason, r.refunded_at,
                    i.invoice_no
               FROM refunds r
               JOIN invoices i ON i.id = r.invoice_id
              WHERE r.organization_id = :org AND i.patient_id = :pid
              ORDER BY r.created_at DESC
              LIMIT 20',
            ['org' => $org, 'pid' => $patientId],
        );

        return [
            'outstanding' => Money::round($outstanding),
            'currency'    => $visible[0]['currency_code'] ?? null,
            'invoices'    => $visible,
            'payments'    => $payments,
            'refunds'     => $refunds,
        ];
    }

    /** One invoice with its lines — ownership re-checked, not assumed. */
    public function invoice(int $invoiceId): array
    {
        $invoice = (new InvoiceRepository())
            ->forOrganization($this->requireOrganization())
            ->findDetailed($invoiceId);

        if ($invoice === null || (int) $invoice['patient_id'] !== $this->meId()) {
            throw new NotFoundException('Invoice not found');
        }
        if ($invoice['status'] === 'draft') {
            throw new NotFoundException('Invoice not found');
        }

        return $invoice;
    }

    /**
     * Link a login to a patient record (§22-style onboarding, clinic side).
     *
     * Called by clinic staff, not by the patient: the clinic decides which
     * record an account may see. Returns a temporary password when a new
     * account is created — a real deployment emails an invite instead (§20).
     *
     * @return array{patient: array<string,mixed>, temporary_password: string|null}
     */
    public function linkAccount(int $patientId, string $email, ?string $name = null): array
    {
        $repo    = $this->patients();
        $patient = $repo->findOrFail($patientId, 'Patient');

        if ($patient['user_id'] !== null) {
            throw new ConflictException('This patient already has an app account.');
        }

        $users    = new \App\Repositories\UserRepository();
        $email    = strtolower(trim($email));
        $existing = $users->firstWhere(['email' => $email]);
        $temp     = null;

        return $this->transaction(function () use (
            $repo, $users, $patient, $patientId, $email, $name, $existing, $temp
        ): array {
            if ($existing !== null) {
                $userId = (int) $existing['id'];

                // One login, one chart. Linking an account that already owns a
                // record here would leave the patient app resolving "my chart"
                // between two rows — and it resolves it by picking one, so the
                // patient would silently see half their history.
                if ($repo->firstWhere(['user_id' => $userId]) !== null) {
                    throw new ConflictException(
                        'That account is already linked to another patient record in this clinic.'
                    );
                }
            } else {
                $temp = strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
                $user = $users->create([
                    'name'       => $name ?? ($patient['first_name'] . ' ' . $patient['last_name']),
                    'email'      => $email,
                    'phone'      => $patient['phone'],
                    'password'   => \App\Repositories\UserRepository::hashPassword($temp),
                    'status'     => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $userId = (int) $user['id'];
            }

            $patientRole = (new \App\Repositories\RoleRepository())->findSystemRole('patient');
            if ($patientRole === null) {
                throw new \RuntimeException('System role "patient" is missing — run database/seed.php');
            }

            $rbac = new RbacService();
            if ($rbac->membership($userId, $this->requireOrganization()) === null) {
                $rbac->addMember($this->requireOrganization(), $userId, (int) $patientRole['id'], 'Patient');
            }

            $repo->update($patientId, ['user_id' => $userId, 'updated_by' => $this->actorId]);

            return [
                'patient'            => $repo->find($patientId) ?? [],
                'temporary_password' => $temp,
            ];
        });
    }
}
