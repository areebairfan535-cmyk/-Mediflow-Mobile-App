<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Services\AuditService;
use App\Services\PatientService;
use App\Services\PrescriptionService;

final class PrescriptionController extends Controller
{
    /**
     * The clinic's prescriptions (§19).
     *
     * §19 names /prescriptions as an endpoint group, and until now the only
     * way in was by id — you could read a prescription you already knew about
     * and not otherwise find one. A pharmacy asking "what is waiting to be
     * dispensed today" had nowhere to ask.
     */
    public function index(Request $request): never
    {
        $filters = $this->validateQuery($request, [
            'patient_id' => 'nullable|integer',
            'doctor_id'  => 'nullable|integer',
            'status'     => 'nullable|in:draft,issued,cancelled',
            'from'       => 'nullable|date',
            'to'         => 'nullable|date',
        ]);

        [$page, $perPage] = $this->pagination($request);

        $result = PrescriptionService::for($request)->search($filters, $page, $perPage);

        $this->ok($result['data'], $result['meta']);
    }

    /**
     * The printable prescription (§4).
     *
     * Rendered on demand rather than stored: a stored file is a second copy of
     * the truth, and the moment a clinician corrects a dosage the two disagree.
     * The document is small and the data is already loaded for the screen.
     *
     * Same access rule as reading it — assertMayAccess lets a patient fetch
     * their own and nobody else's.
     */
    public function pdf(Request $request): never
    {
        $id           = $request->intParam('id');
        $prescription = PrescriptionService::for($request)->show($id);

        PatientService::for($request)->assertMayAccess($request, (int) $prescription['patient_id']);

        if ($prescription['status'] !== 'issued') {
            throw new \App\Core\ConflictException(
                'This prescription has not been issued yet, so there is nothing to print.'
            );
        }

        $clinic = (new \App\Repositories\OrganizationRepository())
            ->settings((int) $request->organizationId()) ?? [];

        (new AuditService())->logPatientAccess(
            $request, (int) $prescription['patient_id'], 'prescription', $id,
        );

        $this->file(
            \App\Services\Documents\ClinicDocuments::prescription($prescription, $clinic),
            $prescription['prescription_no'] . '.pdf',
        );
    }

    public function show(Request $request): never
    {
        $id           = $request->intParam('id');
        $prescription = PrescriptionService::for($request)->show($id);

        PatientService::for($request)->assertMayAccess($request, (int) $prescription['patient_id']);

        (new AuditService())->logPatientAccess(
            $request, (int) $prescription['patient_id'], 'prescription', $id,
        );

        $this->ok(['prescription' => $prescription]);
    }

    public function forPatient(Request $request): never
    {
        $patientId = $request->intParam('patientId');
        PatientService::for($request)->assertMayAccess($request, $patientId);

        $this->ok(['prescriptions' => PrescriptionService::for($request)->forPatient($patientId)]);
    }

    public function store(Request $request): never
    {
        $data = $this->validate($request, [
            'encounter_id'   => 'required|integer',
            'general_advice' => 'nullable|string|max:2000',
        ]);
        $data['items'] = $request->body['items'] ?? [];

        $result = PrescriptionService::for($request)->create($data);

        (new AuditService())->log(
            $request, 'create', 'prescription', (int) $result['prescription']['id'], null,
            ['prescription_no' => $result['prescription']['prescription_no'],
             'items' => count($result['prescription']['items'])],
            (int) $result['prescription']['patient_id'],
        );

        // Allergy warnings ride along with the created resource rather than
        // blocking it — see PrescriptionService::allergyWarnings().
        $this->created([
            'prescription' => $result['prescription'],
            'warnings'     => $result['warnings'],
        ]);
    }

    public function update(Request $request): never
    {
        $data = [];
        if (array_key_exists('general_advice', $request->body)) {
            $data['general_advice'] = $request->body['general_advice'];
        }
        if (array_key_exists('items', $request->body)) {
            $data['items'] = $request->body['items'];
        }

        $id     = $request->intParam('id');
        $result = PrescriptionService::for($request)->update($id, $data);

        (new AuditService())->log(
            $request, 'update', 'prescription', $id,
            ['items' => count($result['before']['items'] ?? [])],
            ['items' => count($result['prescription']['items'])],
            (int) $result['prescription']['patient_id'],
        );

        $this->ok(['prescription' => $result['prescription'], 'warnings' => $result['warnings']]);
    }

    public function issue(Request $request): never
    {
        $id           = $request->intParam('id');
        $result       = PrescriptionService::for($request)->issue($id);
        $prescription = $result['after'];

        (new AuditService())->log(
            $request, 'update', 'prescription', $id,
            ['status' => $result['before']['status']],
            ['status' => 'issued'], (int) $prescription['patient_id'],
        );

        $this->ok(['prescription' => $prescription]);
    }

    public function cancel(Request $request): never
    {
        $id           = $request->intParam('id');
        $result       = PrescriptionService::for($request)->cancel($id);
        $prescription = $result['after'];

        (new AuditService())->log(
            $request, 'update', 'prescription', $id,
            ['status' => $result['before']['status']],
            ['status' => 'cancelled'], (int) $prescription['patient_id'],
        );

        $this->ok(['prescription' => $prescription]);
    }

    /** Medicine catalogue for the prescribing autocomplete (§4: select, don't type). */
    public function medications(Request $request): never
    {
        $q = $this->validateQuery($request, ['search' => 'nullable|string|max:120']);

        $this->ok(['medications' => PrescriptionService::for($request)->medications($q['search'] ?? null)]);
    }
}
