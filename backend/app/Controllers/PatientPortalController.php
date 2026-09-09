<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Services\PatientPortalService;

/**
 * The patient mobile app's surface (§3).
 *
 * Every route here is scoped to the caller's OWN record — no endpoint takes a
 * patient id. That is why these are separate from the clinic-facing
 * /patients/{id} routes rather than reusing them with a different permission.
 */
final class PatientPortalController extends Controller
{
    public function dashboard(Request $request): never
    {
        $data = PatientPortalService::for($request)->dashboard();

        (new AuditService())->logPatientAccess($request, (int) $data['patient']['id']);

        $this->ok($data);
    }

    public function profile(Request $request): never
    {
        $this->ok(['patient' => PatientPortalService::for($request)->profile()]);
    }

    public function updateProfile(Request $request): never
    {
        $data = $this->validate($request, [
            // Personal details — the patient's own facts about themselves.
            'first_name'         => 'nullable|string|min:1|max:120',
            'last_name'          => 'nullable|string|min:1|max:120',
            'date_of_birth'      => 'nullable|date',
            'gender'             => 'nullable|in:male,female,other,unknown',
            // Contact details.
            'phone'              => 'nullable|string|max:32',
            'email'              => 'nullable|email|max:255',
            'address'            => 'nullable|string|max:500',
            'city'               => 'nullable|string|max:120',
            'emergency_name'     => 'nullable|string|max:150',
            'emergency_phone'    => 'nullable|string|max:32',
            'emergency_relation' => 'nullable|string|max:60',
        ]);

        $patient = PatientPortalService::for($request)->updateProfile($data);

        (new AuditService())->log(
            $request, 'update', 'patient', (int) $patient['id'], null, $data, (int) $patient['id'],
        );

        $this->ok(['patient' => $patient]);
    }

    public function appointments(Request $request): never
    {
        $q = $this->validateQuery($request, ['scope' => 'nullable|in:upcoming,past']);

        $this->ok([
            'appointments' => PatientPortalService::for($request)->appointments($q['scope'] ?? null),
        ]);
    }

    // ---------------- booking, from the patient's own app (§3) ----------------

    public function doctors(Request $request): never
    {
        $q = $this->validateQuery($request, [
            'search'    => 'nullable|string|max:120',
            'specialty' => 'nullable|string|max:120',
            'location'  => 'nullable|string|max:120',
        ]);

        $this->ok([
            'doctors' => PatientPortalService::for($request)->bookableDoctors(
                $q['search'] ?? null,
                $q['specialty'] ?? null,
                $q['location'] ?? null,
            ),
        ]);
    }

    /**
     * The values the doctor filters can offer, so the app never presents a
     * specialty or location that would come back empty.
     */
    public function doctorFilters(Request $request): never
    {
        $this->ok(PatientPortalService::for($request)->doctorFilters());
    }

    public function doctorSlots(Request $request): never
    {
        $q = $this->validateQuery($request, ['date' => 'required|date']);

        $this->ok(PatientPortalService::for($request)
            ->availableSlots($request->intParam('id'), (string) $q['date']));
    }

    public function book(Request $request): never
    {
        $data = $this->validate($request, [
            'doctor_id'    => 'required|integer',
            'scheduled_at' => 'required|string|max:25',
            'reason'       => 'nullable|string|max:500',
        ]);

        $appointment = PatientPortalService::for($request)->book($data);

        (new AuditService())->log(
            $request, 'create', 'appointment', (int) $appointment['id'], null,
            ['booked_by' => 'patient', 'doctor_id' => $data['doctor_id']],
            (int) $appointment['patient_id'],
        );

        $this->created([
            'appointment' => $appointment,
            'message'     => 'Booked. The clinic can see it too.',
        ]);
    }

    public function rescheduleAppointment(Request $request): never
    {
        $data = $this->validate($request, [
            'scheduled_at' => 'required|string|max:25',
            'reason'       => 'nullable|string|max:500',
        ]);

        $result = PatientPortalService::for($request)->reschedule(
            $request->intParam('id'),
            (string) $data['scheduled_at'],
            $data['reason'] ?? null,
        );

        (new AuditService())->log(
            $request, 'update', 'appointment', $request->intParam('id'),
            null, ['rescheduled_by' => 'patient', 'to' => $data['scheduled_at']],
        );

        $this->ok(['appointment' => $result['after'] ?? $result]);
    }

    public function cancelAppointment(Request $request): never
    {
        $data = $this->validate($request, ['reason' => 'nullable|string|max:500']);

        $appointment = PatientPortalService::for($request)
            ->cancelAppointment($request->intParam('id'), $data['reason'] ?? null);

        (new AuditService())->log(
            $request, 'update', 'appointment', $request->intParam('id'), null,
            ['status' => 'cancelled', 'by' => 'patient'], (int) $appointment['patient_id'],
        );

        $this->ok(['appointment' => $appointment]);
    }

    public function records(Request $request): never
    {
        $service = PatientPortalService::for($request);
        $records = $service->records();

        (new AuditService())->logPatientAccess($request, (int) $service->me()['id'], 'medical_record');

        $this->ok(['encounters' => $records]);
    }

    public function prescriptions(Request $request): never
    {
        $this->ok(['prescriptions' => PatientPortalService::for($request)->prescriptions()]);
    }

    public function labResults(Request $request): never
    {
        $this->ok(['lab_orders' => PatientPortalService::for($request)->labResults()]);
    }

    public function documents(Request $request): never
    {
        $this->ok(['documents' => PatientPortalService::for($request)->documents()]);
    }

    public function bills(Request $request): never
    {
        $this->ok(PatientPortalService::for($request)->bills());
    }

    public function invoice(Request $request): never
    {
        $invoice = PatientPortalService::for($request)->invoice($request->intParam('id'));

        (new AuditService())->logPatientAccess(
            $request, (int) $invoice['patient_id'], 'invoice', (int) $invoice['id'],
        );

        $this->ok(['invoice' => $invoice]);
    }

    /**
     * A copy of everything, for the person it is about (§16).
     *
     * GDPR Art. 15 and 20, and HIPAA §164.524. Reading a record a screen at a
     * time is not the same right as being able to take it away, so this hands
     * back the whole thing at once, in a format another system can read.
     *
     * Audited like any other access to a chart — more so, since this one is the
     * whole chart leaving at once.
     */
    public function exportMyData(Request $request): never
    {
        $me      = PatientPortalService::for($request)->profile();
        $service = new \App\Services\DataExportService($request->organizationId(), $request->userId());
        $export  = $service->forPatient((int) $me['id']);

        (new AuditService())->log(
            $request, 'export', 'patient_data', (int) $me['id'], null,
            ['requested_by' => 'the patient'], (int) $me['id'],
        );

        $this->ok($export);
    }

    /**
     * Is paying in the app possible at all (§7)?
     *
     * The app asks before it draws a Pay button. A clinic with no gateway
     * configured should not be offering one that can only fail.
     */
    public function paymentStatus(Request $request): never
    {
        $this->ok(\App\Services\Payments\PaymentGateways::status());
    }

    /**
     * Open a payment for one invoice. Takes no amount — see the service.
     */
    public function startPayment(Request $request): never
    {
        $started = PatientPortalService::for($request)->startPayment($request->intParam('id'));

        (new AuditService())->log(
            $request, 'create', 'payment_intent', $request->intParam('id'), null,
            ['gateway' => $started['gateway'], 'reference' => $started['reference']],
        );

        $this->created($started);
    }

    /**
     * Confirm a payment the patient approved at the gateway.
     *
     * The reference is the only thing taken from the caller, and on its own it
     * decides nothing: which invoice it settles and how much comes back from
     * the gateway, and the service re-checks that the invoice is this
     * patient's before a row is written.
     */
    public function confirmPayment(Request $request): never
    {
        $data = $this->validate($request, [
            // min:1 is doing real work here. `required` only asks that the key
            // is there — an empty string satisfies it — and an empty reference
            // would reach the gateway and come back as a 503, which reads as
            // "the provider is down" rather than "you sent nothing".
            'reference' => 'required|string|min:1|max:191',
        ]);

        $result = PatientPortalService::for($request)->confirmPayment((string) $data['reference']);

        (new AuditService())->log(
            $request, 'create', 'payment', (int) ($result['payment']['id'] ?? 0), null,
            [
                'gateway'     => $result['payment']['gateway'] ?? null,
                'gateway_ref' => $result['payment']['gateway_ref'] ?? null,
                'amount'      => $result['payment']['amount'] ?? null,
            ],
        );

        $this->created($result);
    }

    // ---------------- notifications (§20) ----------------

    public function notifications(Request $request): never
    {
        $q = $this->validateQuery($request, ['unread' => 'nullable|boolean']);

        $service = new NotificationService($request->organizationId(), $request->userId());

        $this->ok([
            'notifications' => $service->inbox((int) $request->userId(), (bool) ($q['unread'] ?? false)),
            'unread'        => $service->unreadCount((int) $request->userId()),
        ]);
    }

    public function markNotificationsRead(Request $request): never
    {
        $service = new NotificationService($request->organizationId(), $request->userId());

        // No id in the path means "mark everything read".
        $id    = $request->param('id') !== null ? $request->intParam('id') : null;
        $count = $service->markRead((int) $request->userId(), $id);

        $this->ok(['marked_read' => $count, 'unread' => $service->unreadCount((int) $request->userId())]);
    }

    /**
     * Clear a notification from this person's inbox (§20).
     *
     * The row is not deleted — "was the patient told?" has to stay answerable,
     * and the notification is the record of that. With no id, every notification
     * they have already read is cleared; unread ones are left, because tidying
     * an inbox must not be how a reminder gets lost.
     */
    public function dismissNotifications(Request $request): never
    {
        $service = new NotificationService($request->organizationId(), $request->userId());

        $id  = $request->param('id') !== null ? $request->intParam('id') : null;
        // A selection from the app: DELETE /patient/notifications {"ids":[…]}
        $ids = is_array($request->body['ids'] ?? null) ? $request->body['ids'] : null;
        // "Clear all" — the whole inbox, not just the part the app had loaded.
        $all = filter_var(
            $request->body['all'] ?? ($request->query['all'] ?? false),
            FILTER_VALIDATE_BOOL,
        );

        $count = $service->dismiss((int) $request->userId(), $id, $ids, $all);

        $this->ok([
            'dismissed' => $count,
            'unread'    => $service->unreadCount((int) $request->userId()),
        ]);
    }

    // ---------------- clinic side: give a patient an app account ----------------

    public function linkAccount(Request $request): never
    {
        $data = $this->validate($request, [
            'email' => 'required|email|max:255',
            'name'  => 'nullable|string|max:255',
        ]);

        $result = PatientPortalService::for($request)->linkAccount(
            $request->intParam('id'),
            (string) $data['email'],
            $data['name'] ?? null,
        );

        (new AuditService())->log(
            $request, 'create', 'patient_account', $request->intParam('id'), null,
            ['email' => $data['email']], $request->intParam('id'),
        );

        $payload = ['patient' => $result['patient']];
        if ($result['temporary_password'] !== null) {
            $payload['temporary_password'] = $result['temporary_password'];
            $payload['message'] = 'Account created. Share this password securely; '
                                . 'the patient should change it on first sign-in.';
        } else {
            $payload['message'] = 'Existing account linked to this patient record.';
        }

        $this->created($payload);
    }
}
