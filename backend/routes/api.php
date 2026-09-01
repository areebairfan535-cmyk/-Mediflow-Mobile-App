<?php
declare(strict_types=1);

/*
 * Route table (§14). Everything lives under /api/v1 so a future v2 can ship
 * alongside without breaking installed mobile apps.
 *
 * Middleware aliases, in the order they must run:
 *   throttle[:auth]  rate limiting (§17)
 *   auth             resolve Bearer access token -> user
 *   tenant           resolve + VERIFY active organization (§10)
 *   perm:slug[,slug] RBAC gate, any-of (§11)
 *   platform         platform administrator only (§21)
 *
 * `tenant` must come after `auth`, and `perm` after `tenant`.
 */

use App\Controllers\AiController;
use App\Controllers\AppointmentController;
use App\Controllers\AuditLogController;
use App\Controllers\AuthController;
use App\Controllers\BillingController;
use App\Controllers\ClinicalController;
use App\Controllers\DoctorController;
use App\Controllers\EncounterController;
use App\Controllers\HealthController;
use App\Controllers\InsuranceController;
use App\Controllers\MeController;
use App\Controllers\OrganizationController;
use App\Controllers\PatientController;
use App\Controllers\PatientPortalController;
use App\Controllers\PlatformController;
use App\Controllers\PrescriptionController;
use App\Controllers\PublicController;
use App\Controllers\SubscriptionController;

/** @var \App\Core\Router $router */

$router->group('/api/v1', [], function ($router): void {

    // ---------------- Public ----------------
    $router->get('/health', [HealthController::class, 'index']);

    // §22 begins with "choose plan", which happens before an account exists —
    // so the price list and the open markets are readable without one. Both are
    // public facts; neither carries tenant data.
    $router->get('/public/plans',     [PublicController::class, 'plans']);
    $router->get('/public/countries', [PublicController::class, 'countries']);

    // Auth endpoints are brute-force targets: tighter throttle bucket.
    $router->group('/auth', ['throttle:auth'], function ($router): void {
        $router->post('/register', [AuthController::class, 'register']);
        $router->post('/login',    [AuthController::class, 'login']);
        $router->post('/refresh',  [AuthController::class, 'refresh']);

        // Forgotten passwords (§11). Public by necessity — the person
        // cannot sign in, which is the whole problem. The code is short
        // enough to type off a phone, so the throttle above is what keeps
        // it from being guessed, and the service retires it after five
        // wrong tries.
        $router->post('/forgot-password', [AuthController::class, 'forgotPassword']);
        $router->post('/reset-password',  [AuthController::class, 'resetPassword']);
    });

    // ---------------- Authenticated, no tenant required ----------------
    // These work before the user belongs to any organization, which is what
    // makes onboarding possible (§22: register -> create org -> add staff).
    $router->group('', ['throttle', 'auth'], function ($router): void {

        $router->post('/auth/logout',                [AuthController::class, 'logout']);
        $router->post('/auth/logout-all',            [AuthController::class, 'logoutAll']);
        $router->post('/auth/change-password',       [AuthController::class, 'changePassword']);
        $router->post('/auth/switch-organization',   [AuthController::class, 'switchOrganization']);
        $router->get('/auth/sessions',               [AuthController::class, 'sessions']);
        $router->delete('/auth/sessions/{id}',       [AuthController::class, 'revokeSession']);

        $router->get('/me', [MeController::class, 'show']);
        $router->put('/me', [MeController::class, 'update']);

        // Clinic onboarding: any authenticated user may create an organization
        // and becomes its owner.
        $router->post('/organizations', [OrganizationController::class, 'store']);
    });

    // ---------------- Authenticated + tenant-scoped ----------------
    $router->group('', ['throttle', 'auth', 'tenant'], function ($router): void {

        // /me repeated inside the tenant group so the response can include the
        // active organization, its role and the resolved permission list.
        $router->get('/me/context', [MeController::class, 'show']);

        $router->group('/organizations/current', [], function ($router): void {
            $router->get('',  [OrganizationController::class, 'current']);
            $router->put('',  [OrganizationController::class, 'updateCurrent'],
                ['perm:organization.update']);

            $router->get('/roles', [OrganizationController::class, 'roles'],
                ['perm:member.view']);

            $router->get('/members', [OrganizationController::class, 'members'],
                ['perm:member.view']);
            $router->post('/members', [OrganizationController::class, 'addMember'],
                ['perm:member.create']);
            $router->put('/members/{userId}/role', [OrganizationController::class, 'changeMemberRole'],
                ['perm:member.update']);
            $router->put('/members/{userId}/status', [OrganizationController::class, 'changeMemberStatus'],
                ['perm:member.update']);
            $router->delete('/members/{userId}', [OrganizationController::class, 'removeMember'],
                ['perm:member.delete']);

            // §22. Readable by anyone who can see the team — "why was I
            // refused" is a front-desk question — but only the owner may
            // change what the clinic pays for.
            $router->get('/subscription', [SubscriptionController::class, 'show'],
                ['perm:member.view']);
            $router->put('/subscription', [SubscriptionController::class, 'update'],
                ['perm:subscription.manage']);
        });

        // The price list (§22). Not tenant data, but only shown to a signed-in
        // clinic, so it sits inside the tenant group with the rest.
        $router->get('/plans', [SubscriptionController::class, 'plans'], ['perm:member.view']);

        // Audit trail (§16). Static path registered as a sibling of the
        // dynamic one; Router sorts by specificity so ordering cannot bite.
        $router->get('/audit-logs', [AuditLogController::class, 'index'],
            ['perm:audit.view']);
        $router->get('/audit-logs/patient/{patientId}', [AuditLogController::class, 'forPatient'],
            ['perm:audit.view']);
        $router->get('/audit-logs/{type}/{id}', [AuditLogController::class, 'forResource'],
            ['perm:audit.view']);

        // ==========================================================
        // PHASE 2 — Core Healthcare (§25)
        // ==========================================================

        // ---- Patients (§3, §5) ----
        $router->get('/patients',      [PatientController::class, 'index'],  ['perm:patient.view']);
        $router->post('/patients',     [PatientController::class, 'store'],  ['perm:patient.create']);
        $router->get('/patients/{id}', [PatientController::class, 'show'],   ['perm:patient.view']);
        $router->put('/patients/{id}', [PatientController::class, 'update'], ['perm:patient.update']);
        $router->delete('/patients/{id}', [PatientController::class, 'destroy'], ['perm:patient.delete']);

        $router->get('/patients/{id}/allergies',  [PatientController::class, 'allergies'],  ['perm:patient.view']);
        $router->post('/patients/{id}/allergies', [PatientController::class, 'addAllergy'], ['perm:patient.update']);
        $router->delete('/patients/{id}/allergies/{allergyId}',
            [PatientController::class, 'removeAllergy'], ['perm:patient.update']);

        $router->get('/patients/{id}/conditions',  [PatientController::class, 'conditions'],   ['perm:patient.view']);
        $router->post('/patients/{id}/conditions', [PatientController::class, 'addCondition'], ['perm:patient.update']);
        $router->put('/patients/{id}/conditions/{conditionId}',
            [PatientController::class, 'updateCondition'], ['perm:patient.update']);

        $router->get('/patients/{patientId}/prescriptions',
            [PrescriptionController::class, 'forPatient'], ['perm:prescription.view']);
        $router->get('/patients/{patientId}/documents',
            [ClinicalController::class, 'documents'], ['perm:document.view']);
        $router->post('/patients/{patientId}/documents',
            [ClinicalController::class, 'upload'], ['perm:document.upload']);

        // ---- Doctors (§4) ----
        // /doctors/dashboard is a sibling of /doctors/{id}; the router orders
        // by specificity, so the static path always wins.
        $router->get('/doctors',           [DoctorController::class, 'index'], ['perm:patient.view']);
        $router->get('/doctors/dashboard', [DoctorController::class, 'dashboard'], ['perm:encounter.view']);
        $router->post('/doctors',          [DoctorController::class, 'store'],  ['perm:member.create']);
        $router->get('/doctors/{id}',      [DoctorController::class, 'show'],   ['perm:patient.view']);
        $router->put('/doctors/{id}',      [DoctorController::class, 'update'], ['perm:member.update,schedule.manage']);

        $router->get('/doctors/{id}/schedule', [DoctorController::class, 'schedule'], ['perm:appointment.view']);
        $router->put('/doctors/{id}/schedule', [DoctorController::class, 'updateSchedule'], ['perm:schedule.manage']);
        $router->get('/doctors/{id}/available-slots',
            [DoctorController::class, 'availableSlots'], ['perm:appointment.view']);

        // ---- Appointments (§3, §4) ----
        $router->get('/appointments',      [AppointmentController::class, 'index'], ['perm:appointment.view']);
        $router->post('/appointments',     [AppointmentController::class, 'store'], ['perm:appointment.create']);
        $router->get('/appointments/{id}', [AppointmentController::class, 'show'],  ['perm:appointment.view']);
        $router->put('/appointments/{id}/reschedule',
            [AppointmentController::class, 'reschedule'], ['perm:appointment.update']);
        $router->put('/appointments/{id}/status',
            [AppointmentController::class, 'changeStatus'], ['perm:appointment.update,appointment.cancel']);

        // ---- Encounters / consultations (§4, §5) ----
        $router->get('/encounters',      [EncounterController::class, 'index'], ['perm:encounter.view']);
        $router->post('/encounters',     [EncounterController::class, 'store'], ['perm:encounter.create']);
        $router->get('/encounters/{id}', [EncounterController::class, 'show'],  ['perm:encounter.view']);
        $router->put('/encounters/{id}', [EncounterController::class, 'update'], ['perm:encounter.update']);
        $router->post('/encounters/{id}/complete', [EncounterController::class, 'complete'], ['perm:encounter.update']);
        $router->post('/encounters/{id}/cancel',   [EncounterController::class, 'cancel'],   ['perm:encounter.update']);

        $router->post('/encounters/{id}/diagnoses',  [EncounterController::class, 'addDiagnosis'], ['perm:diagnosis.manage']);
        $router->post('/encounters/{id}/procedures', [EncounterController::class, 'addProcedure'], ['perm:procedure.manage']);
        $router->post('/encounters/{id}/notes',      [EncounterController::class, 'addNote'],      ['perm:encounter.update']);
        $router->post('/encounters/{id}/lab-orders', [EncounterController::class, 'orderLab'],     ['perm:lab.create']);
        $router->delete('/encounters/{id}/{kind}/{childId}',
            [EncounterController::class, 'removeChild'], ['perm:encounter.update']);

        // ---- Prescriptions (§4) ----
        // Static /prescriptions/medications before the {id} pattern.
        $router->get('/prescriptions/medications',
            [PrescriptionController::class, 'medications'], ['perm:prescription.view']);
        $router->post('/prescriptions',     [PrescriptionController::class, 'store'], ['perm:prescription.create']);
        $router->get('/prescriptions/{id}/pdf', [PrescriptionController::class, 'pdf'], ['perm:prescription.view']);
        $router->get('/prescriptions/{id}', [PrescriptionController::class, 'show'],  ['perm:prescription.view']);
        $router->put('/prescriptions/{id}', [PrescriptionController::class, 'update'], ['perm:prescription.create']);
        $router->post('/prescriptions/{id}/issue',  [PrescriptionController::class, 'issue'],  ['perm:prescription.create']);
        $router->post('/prescriptions/{id}/cancel', [PrescriptionController::class, 'cancel'], ['perm:prescription.create']);

        // ---- Labs & documents (§5, §19) ----
        $router->get('/lab-orders', [ClinicalController::class, 'labOrders'], ['perm:lab.view']);
        $router->post('/lab-orders/{id}/results',
            [ClinicalController::class, 'recordLabResults'], ['perm:lab.result']);
        $router->get('/documents/{id}/download', [ClinicalController::class, 'download'], ['perm:document.view']);

        // ==========================================================
        // PHASE 3 — Billing (§6, §7, §25)
        // ==========================================================

        // ---- Service catalogue & pricing (§6, §23) ----
        $router->get('/services',            [BillingController::class, 'services'],         ['perm:service.view']);
        $router->post('/services',           [BillingController::class, 'storeService'],     ['perm:service.manage']);
        $router->put('/services/{id}',       [BillingController::class, 'updateService'],    ['perm:service.manage']);
        $router->post('/services/{id}/prices', [BillingController::class, 'addServicePrice'], ['perm:service.manage']);

        // ---- Invoices (§6) ----
        $router->get('/invoices',      [BillingController::class, 'invoices'],      ['perm:invoice.view']);
        $router->post('/invoices',     [BillingController::class, 'storeInvoice'],  ['perm:invoice.create']);
        $router->get('/invoices/{id}/pdf', [BillingController::class, 'invoicePdf'], ['perm:invoice.view']);
        $router->get('/invoices/{id}', [BillingController::class, 'showInvoice'],   ['perm:invoice.view']);
        $router->put('/invoices/{id}', [BillingController::class, 'updateInvoice'], ['perm:invoice.update']);
        $router->post('/invoices/{id}/issue',  [BillingController::class, 'issueInvoice'],  ['perm:invoice.issue']);
        $router->post('/invoices/{id}/cancel', [BillingController::class, 'cancelInvoice'], ['perm:invoice.cancel']);

        // §27: consultation -> draft invoice.
        $router->post('/encounters/{id}/invoice',
            [BillingController::class, 'invoiceFromEncounter'], ['perm:invoice.create']);

        // ---- Payments & refunds (§7) ----
        $router->get('/payments', [BillingController::class, 'payments'], ['perm:payment.view']);
        $router->post('/invoices/{id}/payments',
            [BillingController::class, 'recordPayment'], ['perm:payment.create']);

        // Static /refunds/pending must be registered as a sibling of the
        // {id} routes; the router orders by specificity, so it always wins.
        $router->get('/refunds/pending', [BillingController::class, 'pendingRefunds'], ['perm:refund.approve']);
        $router->post('/payments/{id}/refunds', [BillingController::class, 'requestRefund'], ['perm:refund.create']);
        $router->post('/refunds/{id}/approve',  [BillingController::class, 'approveRefund'], ['perm:refund.approve']);
        $router->post('/refunds/{id}/reject',   [BillingController::class, 'rejectRefund'],  ['perm:refund.approve']);

        // ---- Financial reports (§25 Phase 3) ----
        $router->get('/reports/financial',   [BillingController::class, 'reports'],          ['perm:report.view']);
        $router->get('/reports/receivables', [BillingController::class, 'agedReceivables'],  ['perm:report.view']);
        $router->post('/invoices/mark-overdue', [BillingController::class, 'markOverdue'],   ['perm:invoice.update']);

        // ==========================================================
        // PHASE 4 — Patient app surface (§3, §25)
        // ==========================================================
        //
        // These routes carry NO perm: guard, and that is deliberate.
        //
        // Every one resolves the record from the signed-in account
        // (patients.user_id) and none takes a patient id, so they are scoped by
        // IDENTITY rather than by permission. Adding the obvious-looking slugs
        // would be actively harmful: `invoice.view` also opens the clinic-wide
        // GET /invoices, and `appointment.cancel` also opens
        // PUT /appointments/{id}/status for every appointment in the clinic.
        //
        // A caller with no linked patient record gets 403 from
        // PatientPortalService::me(), so staff cannot wander in here either.
        $router->group('/patient', [], function ($router): void {
            $router->get('/dashboard',     [PatientPortalController::class, 'dashboard']);
            $router->get('/profile',       [PatientPortalController::class, 'profile']);
            $router->put('/profile',       [PatientPortalController::class, 'updateProfile']);

            $router->get('/appointments',  [PatientPortalController::class, 'appointments']);

            // §3: the patient books for themselves. patient_id comes from the
            // session, never the request, so this cannot write into somebody
            // else's calendar — which is why it needs no perm: guard.
            $router->get('/doctors',              [PatientPortalController::class, 'doctors']);
            $router->get('/doctors/{id}/slots',   [PatientPortalController::class, 'doctorSlots']);
            $router->post('/appointments',        [PatientPortalController::class, 'book']);
            $router->post('/appointments/{id}/reschedule',
                [PatientPortalController::class, 'rescheduleAppointment']);
            $router->post('/appointments/{id}/cancel',
                [PatientPortalController::class, 'cancelAppointment']);

            $router->get('/records',       [PatientPortalController::class, 'records']);
            $router->get('/prescriptions', [PatientPortalController::class, 'prescriptions']);

            // §4: the patient's own copy of the printable documents. Same
            // handlers as the clinic's — assertMayAccess already limits a
            // patient to their own, so no perm: guard is needed or wanted.
            $router->get('/prescriptions/{id}/pdf', [PrescriptionController::class, 'pdf']);
            $router->get('/invoices/{id}/pdf',      [BillingController::class, 'invoicePdf']);
            $router->get('/lab-results',   [PatientPortalController::class, 'labResults']);
            $router->get('/documents',     [PatientPortalController::class, 'documents']);

            // §3: a lab report you can see listed but cannot open is not a
            // record you have. Same handler as the clinic's — it already
            // refuses anything not marked patient_visible, and refuses another
            // patient's file with a 404 rather than a 403.
            $router->get('/documents/{id}/download', [ClinicalController::class, 'download']);

            $router->get('/bills',         [PatientPortalController::class, 'bills']);
            $router->get('/invoices/{id}', [PatientPortalController::class, 'invoice']);

            // §20 in-app inbox.
            $router->get('/notifications', [PatientPortalController::class, 'notifications']);
            $router->post('/notifications/read',
                [PatientPortalController::class, 'markNotificationsRead']);
            $router->post('/notifications/{id}/read',
                [PatientPortalController::class, 'markNotificationsRead']);

            // Clear from the inbox. Hides the row, never deletes it (§20).
            $router->delete('/notifications/{id}',
                [PatientPortalController::class, 'dismissNotifications']);
            $router->delete('/notifications',
                [PatientPortalController::class, 'dismissNotifications']);
        });

        // Clinic side: hand a patient an app account.
        $router->post('/patients/{id}/account',
            [PatientPortalController::class, 'linkAccount'], ['perm:patient.update']);

        // ==========================================================
        // PHASE 5 — Insurance & claims (§8, §25)
        // ==========================================================

        // ---- Providers & policies ----
        $router->get('/insurance/providers',  [InsuranceController::class, 'providers'],
            ['perm:policy.view']);
        $router->post('/insurance/providers', [InsuranceController::class, 'storeProvider'],
            ['perm:policy.manage']);

        $router->get('/patients/{patientId}/policies',  [InsuranceController::class, 'policies'],
            ['perm:policy.view']);
        $router->post('/patients/{patientId}/policies', [InsuranceController::class, 'storePolicy'],
            ['perm:policy.manage']);
        $router->put('/insurance/policies/{id}', [InsuranceController::class, 'updatePolicy'],
            ['perm:policy.manage']);

        // ---- Eligibility (§25 Phase 5) ----
        $router->get('/invoices/{id}/eligibility', [InsuranceController::class, 'checkInvoice'],
            ['perm:policy.view']);
        $router->post('/insurance/check',          [InsuranceController::class, 'checkAmount'],
            ['perm:policy.view']);

        // ---- Claims (§8) ----
        // Static /claims/pipeline before the {id} pattern; the router sorts by
        // specificity, so ordering here is belt and braces.
        $router->get('/claims/pipeline', [InsuranceController::class, 'pipeline'],
            ['perm:claim.view']);
        $router->get('/claims',          [InsuranceController::class, 'claims'],  ['perm:claim.view']);
        $router->post('/claims',         [InsuranceController::class, 'createClaim'], ['perm:claim.create']);
        $router->get('/claims/{id}',     [InsuranceController::class, 'showClaim'], ['perm:claim.view']);
        $router->delete('/claims/{id}',  [InsuranceController::class, 'deleteClaim'], ['perm:claim.create']);

        $router->post('/claims/{id}/submit',     [InsuranceController::class, 'submitClaim'],
            ['perm:claim.submit']);
        $router->post('/claims/{id}/processing', [InsuranceController::class, 'processingClaim'],
            ['perm:claim.update']);
        $router->post('/claims/{id}/decision',   [InsuranceController::class, 'decideClaim'],
            ['perm:claim.update']);
        $router->post('/claims/{id}/paid',       [InsuranceController::class, 'payClaim'],
            ['perm:claim.update']);
        $router->post('/claims/{id}/resubmit',   [InsuranceController::class, 'resubmitClaim'],
            ['perm:claim.submit']);

        // ==========================================================
        // PHASE 6 — AI assistants (§9, §25, §28)
        // ==========================================================
        //
        // Optional by design. With no provider configured every route below
        // returns 503 with a plain message and nothing else is affected —
        // §26 keeps the AI module outside the MVP.
        //
        // Note what is NOT here: no endpoint that lets the AI write a final
        // record. Drafting and approving are separate calls with separate
        // permissions, which is §9's "human confirmation" made structural.
        $router->get('/ai/status', [AiController::class, 'status']);

        $router->post('/encounters/{id}/ai/draft-note',
            [AiController::class, 'draftNote'], ['perm:ai.draft_note']);
        $router->post('/clinical-notes/{id}/approve',
            [AiController::class, 'approveNote'], ['perm:encounter.update']);
        $router->delete('/clinical-notes/{id}',
            [AiController::class, 'discardNote'], ['perm:encounter.update']);

        $router->get('/encounters/{id}/ai/billing-suggestions',
            [AiController::class, 'suggestBilling'], ['perm:ai.suggest_billing']);

        $router->get('/claims/{id}/ai/review',
            [AiController::class, 'reviewClaim'], ['perm:ai.check_claim']);

        // §25 Phase 6 also names a patient summary and an intelligent search.
        // The summary needs a provider; the search does not — it is SQL across
        // several surfaces at once, and works with AI switched off.
        $router->get('/patients/{id}/ai/summary',
            [AiController::class, 'summarisePatient'], ['perm:ai.draft_note']);
        $router->get('/search', [AiController::class, 'search'], ['perm:patient.view']);
    });

    // ---------------- Platform administration (§21) ----------------
    // Cross-tenant by design. Note: 'platform', NOT 'tenant'.
    $router->group('/platform', ['throttle', 'auth', 'platform'], function ($router): void {
        $router->get('/dashboard',                  [PlatformController::class, 'dashboard']);
        $router->get('/organizations',              [PlatformController::class, 'organizations']);
        $router->get('/organizations/{id}',         [PlatformController::class, 'showOrganization']);
        $router->put('/organizations/{id}/status',  [PlatformController::class, 'setOrganizationStatus']);
        $router->put('/organizations/{id}/plan',    [PlatformController::class, 'setOrganizationPlan']);

        // The price list itself (§21, §22) — one clinic's copy lives at
        // /organizations/current/subscription and is a different thing.
        $router->get('/plans',       [PlatformController::class, 'plans']);
        $router->post('/plans',      [PlatformController::class, 'storePlan']);
        $router->put('/plans/{id}',  [PlatformController::class, 'updatePlan']);

        // The cross-tenant trail (§16). /audit-logs is tenant-scoped and
        // cannot show platform-level rows to anyone; this can.
        $router->get('/audit-logs', [PlatformController::class, 'auditLogs']);

        // Countries, currencies and tax (§21, §23). Nothing about a market is
        // hard-coded; the row IS the configuration.
        $router->get('/countries',      [PlatformController::class, 'countries']);
        $router->post('/countries',     [PlatformController::class, 'storeCountry']);
        $router->put('/countries/{id}', [PlatformController::class, 'updateCountry']);
    });
});

/*
 * ---------------------------------------------------------------------------
 * NOT YET IMPLEMENTED — later phases (§25). Listed so the API surface the
 * plan asks for is visible in one place, and so nobody re-invents the path
 * naming when they get here.
 *
 * Phase 4 — Patient app surface
 *   /patient/dashboard, /patient/records, /patient/bills, /notifications
 * Phase 5 — Insurance
 *   /insurance/providers, /insurance/policies, /claims
 * Phase 6 — AI
 *   /ai/billing-suggestions, /ai/clinical-note, /ai/claim-check
 * ---------------------------------------------------------------------------
 */
