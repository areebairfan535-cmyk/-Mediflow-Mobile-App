<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ConflictException;
use App\Core\ForbiddenException;
use App\Core\NotFoundException;
use App\Core\Service;
use App\Repositories\ClinicalRepository;
use App\Repositories\InvoiceRepository;
use App\Repositories\PatientPortalRepository;
use App\Repositories\PatientRepository;
use App\Repositories\PrescriptionRepository;
use App\Services\Billing\Money;
use App\Services\Payments\GatewayUnavailable;
use App\Services\Payments\PaymentGateways;

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

    /** The portal's own reads — every one of them filtered to one patient. */
    private function portal(): PatientPortalRepository
    {
        return (new PatientPortalRepository())->forOrganization($this->requireOrganization());
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
        $patient   = $this->patients()->withClinicalSummary($this->meId());
        $patientId = (int) $patient['id'];
        $portal    = $this->portal();

        $upcoming = $portal->upcomingAppointments($patientId);
        $bills    = $portal->openBills($patientId);

        $outstanding = Money::sum(array_column($bills, 'balance_due'));

        $prescriptions = $portal->issuedPrescriptions($patientId);

        // §3 "treatment reminders": follow-ups the doctor asked for.
        $followUps = $portal->upcomingFollowUps($patientId);

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

        $patient['insurance'] = $this->portal()->insurancePolicies((int) $patient['id']);

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
        return $this->portal()->appointments($this->meId(), $scope);
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
        return $this->portal()->countFor($this->meId(), $table, $predicate);
    }

    private function lastVisitDate(): ?string
    {
        return $this->portal()->lastVisitDate($this->meId());
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
    public function bookableDoctors(
        ?string $search = null,
        ?string $specialty = null,
        ?string $location = null,
    ): array {
        return $this->portal()->bookableDoctors($search, $specialty, $location);
    }

    /**
     * The specialties and locations this clinic actually has doctors in (§3).
     *
     * Read from the doctors themselves rather than a fixed list, so the filter
     * can never offer a choice that returns nothing. Doctors who have stopped
     * accepting patients are excluded for the same reason.
     *
     * @return array{specialties: list<string>, locations: list<string>}
     */
    public function doctorFilters(): array
    {
        $rows = $this->portal()->doctorFilterValues();

        $specialties = [];
        $locations   = [];
        foreach ($rows as $row) {
            $specialty = trim((string) ($row['specialty'] ?? ''));
            $location  = trim((string) ($row['location'] ?? ''));
            if ($specialty !== '') {
                $specialties[$specialty] = true;
            }
            if ($location !== '') {
                $locations[$location] = true;
            }
        }

        $specialties = array_keys($specialties);
        $locations   = array_keys($locations);
        sort($specialties);
        sort($locations);

        return ['specialties' => $specialties, 'locations' => $locations];
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
        $appointment = $this->portal()->ownAppointment($this->meId(), $appointmentId);

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
        $appointment = $this->portal()->ownAppointment($patientId, $appointmentId);

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
        $portal     = $this->portal();
        $encounters = $portal->completedEncounters($this->meId());

        foreach ($encounters as $i => $encounter) {
            $encounterId = (int) $encounter['id'];

            $encounters[$i]['diagnoses']  = $portal->encounterDiagnoses($encounterId);
            $encounters[$i]['procedures'] = $portal->encounterProcedures($encounterId);
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

        $portal   = $this->portal();
        $payments = $portal->paymentHistory($patientId);
        $refunds  = $portal->refundHistory($patientId);

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
     * Open an online payment for one of this patient's invoices (§7).
     *
     * The amount is read off the invoice here and never accepted from the
     * caller. A patient who could name their own figure would be able to
     * settle a 50,000 bill with a 50 payment that the ledger then records as
     * genuine, because by the time it comes back from the gateway it is a real
     * captured payment.
     *
     * Nothing is written yet. An abandoned checkout leaves no payment row and
     * no balance change — the invoice is untouched until money actually moves.
     *
     * @return array<string,mixed>
     */
    public function startPayment(int $invoiceId): array
    {
        $invoice = $this->invoice($invoiceId);
        $gateway = PaymentGateways::resolve();

        if (!$gateway->isConfigured()) {
            throw new GatewayUnavailable((string) $gateway->unavailableReason());
        }

        $balance = Money::round((string) $invoice['balance_due']);

        if (Money::compare($balance, '0') <= 0) {
            throw new ConflictException('This invoice is already settled.');
        }

        $appUrl = rtrim((string) env('APP_URL', 'http://localhost:8000'), '/');
        $return = (string) env('PAYMENT_RETURN_URL', $appUrl . '/payment/return');
        $cancel = (string) env('PAYMENT_CANCEL_URL', $appUrl . '/payment/cancel');

        $started = $gateway->createPayment($balance, (string) $invoice['currency_code'], [
            'invoice_id'  => (string) $invoice['id'],
            'invoice_no'  => (string) ($invoice['invoice_no'] ?? ''),
            'description' => 'Invoice ' . ($invoice['invoice_no'] ?? $invoice['id']),
            'return_url'  => $return,
            'cancel_url'  => $cancel,
        ]);

        return [
            'gateway'      => $gateway->name(),
            'reference'    => $started['reference'],
            'approval_url' => $started['approval_url'],
            'amount'       => $balance,
            'currency'     => $invoice['currency_code'],
        ];
    }

    /**
     * Take the money the patient approved, and write it to the ledger (§7).
     *
     * Everything here is checked against the gateway's answer rather than the
     * app's request, because between opening a payment and confirming it the
     * client has been to another company's website and back:
     *
     *   - which invoice was paid comes from the gateway, not the caller;
     *   - the invoice must still belong to this patient;
     *   - the same reference is recorded once, so a refreshed browser or a
     *     retried request cannot become two payments;
     *   - the amount written is the amount captured, not the amount asked for.
     *
     * @return array<string,mixed>
     */
    public function confirmPayment(string $reference): array
    {
        $gateway = PaymentGateways::resolve();
        $result  = $gateway->capture($reference);

        // Already recorded? Then this is a repeat of a call that worked, and
        // the honest answer is the payment that already exists — not an error,
        // and certainly not a second row.
        $existing = $this->portal()->findByGatewayRef($gateway->name(), $result['reference']);
        if ($existing !== null) {
            return ['payment' => $existing, 'invoice' => $this->invoice((int) $existing['invoice_id'])];
        }

        $invoiceId = (int) $result['invoice_id'];
        if ($invoiceId <= 0) {
            throw new GatewayUnavailable(
                'The payment went through but the gateway did not say which invoice it was for. '
                . 'The clinic has the reference and will apply it.'
            );
        }

        // Re-runs invoice(): still this patient's, still not a draft.
        $invoice = $this->invoice($invoiceId);
        $captured = Money::round((string) $result['amount']);
        $balance  = Money::round((string) $invoice['balance_due']);

        // The money is already taken, so this cannot simply refuse. It refuses
        // to *invent a ledger entry that does not balance*, and leaves the
        // reference where the clinic can find it.
        if (Money::greaterThan($captured, $balance)) {
            error_log(sprintf(
                '[payment] captured %s %s against invoice %d which owes %s — reference %s',
                $result['currency'], $captured, $invoiceId, $balance, $result['reference'],
            ));

            throw new ConflictException(
                'Your payment went through, but it is more than this invoice now owes — '
                . 'someone may have paid part of it already. The clinic has the reference '
                . 'and will refund the difference.'
            );
        }

        $recorded = (new PaymentService($this->organizationId, $this->actorId))->record($invoiceId, [
            'amount'      => $captured,
            'method'      => 'online',
            'status'      => 'succeeded',
            'gateway'     => $gateway->name(),
            'gateway_ref' => $result['reference'],
            'paid_at'     => $result['paid_at'],
            'notes'       => 'Paid by the patient in the app',
        ]);

        return $recorded;
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
