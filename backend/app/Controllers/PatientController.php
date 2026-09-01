<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Services\AuditService;
use App\Services\PatientService;

final class PatientController extends Controller
{
    public function index(Request $request): never
    {
        $q = $this->validateQuery($request, [
            'search' => 'nullable|string|max:120',
            'status' => 'nullable|in:active,inactive,merged',
        ]);

        [$page, $perPage] = $this->pagination($request);

        $result = PatientService::for($request)
            ->search($q['search'] ?? null, $q['status'] ?? null, $page, $perPage);

        $this->ok($result['data'], $result['meta']);
    }

    public function show(Request $request): never
    {
        $id      = $request->intParam('id');
        $service = PatientService::for($request);

        $service->assertMayAccess($request, $id);
        $patient = $service->show($id);

        // §16 requires ACCESS to a patient record to be audited, not just writes.
        (new AuditService())->logPatientAccess($request, $id);

        $this->ok(['patient' => $patient]);
    }

    public function store(Request $request): never
    {
        $data = $this->validate($request, [
            'first_name'         => 'required|string|min:1|max:120',
            'last_name'          => 'required|string|min:1|max:120',
            'date_of_birth'      => 'nullable|date',
            'gender'             => 'nullable|in:male,female,other,unknown',
            'phone'              => 'nullable|string|max:32',
            'email'              => 'nullable|email|max:255',
            'address'            => 'nullable|string|max:500',
            'city'               => 'nullable|string|max:120',
            'blood_group'        => 'nullable|string|max:8',
            'emergency_name'     => 'nullable|string|max:150',
            'emergency_phone'    => 'nullable|string|max:32',
            'emergency_relation' => 'nullable|string|max:60',
            'notes'              => 'nullable|string|max:5000',
        ]);

        $patient = PatientService::for($request)->register($data);

        (new AuditService())->log(
            $request, 'create', 'patient', (int) $patient['id'], null,
            ['mrn' => $patient['mrn'], 'name' => $patient['first_name'] . ' ' . $patient['last_name']],
            (int) $patient['id'],
        );

        $this->created(['patient' => $patient]);
    }

    public function update(Request $request): never
    {
        $data = $this->validate($request, [
            'first_name'         => 'nullable|string|min:1|max:120',
            'last_name'          => 'nullable|string|min:1|max:120',
            'date_of_birth'      => 'nullable|date',
            'gender'             => 'nullable|in:male,female,other,unknown',
            'phone'              => 'nullable|string|max:32',
            'email'              => 'nullable|email|max:255',
            'address'            => 'nullable|string|max:500',
            'city'               => 'nullable|string|max:120',
            'blood_group'        => 'nullable|string|max:8',
            'emergency_name'     => 'nullable|string|max:150',
            'emergency_phone'    => 'nullable|string|max:32',
            'emergency_relation' => 'nullable|string|max:60',
            'notes'              => 'nullable|string|max:5000',
        ]);

        $id     = $request->intParam('id');
        $result = PatientService::for($request)->update($id, $data);

        (new AuditService())->logUpdate($request, 'patient', $id, $result['before'], $result['after'], $id);

        $this->ok(['patient' => $result['after']]);
    }

    public function destroy(Request $request): never
    {
        $id      = $request->intParam('id');
        $patient = PatientService::for($request)->deactivate($id);

        (new AuditService())->log($request, 'update', 'patient', $id, null, ['status' => 'inactive'], $id);

        $this->ok(['patient' => $patient]);
    }

    // ---- allergies ----

    public function allergies(Request $request): never
    {
        $id = $request->intParam('id');
        PatientService::for($request)->assertMayAccess($request, $id);

        $this->ok(['allergies' => PatientService::for($request)->allergies($id)]);
    }

    public function addAllergy(Request $request): never
    {
        $data = $this->validate($request, [
            'substance' => 'required|string|max:150',
            'reaction'  => 'nullable|string|max:255',
            'severity'  => 'nullable|in:mild,moderate,severe,life_threatening',
            'noted_on'  => 'nullable|date',
        ]);

        $id      = $request->intParam('id');
        $allergy = PatientService::for($request)->addAllergy($id, $data);

        (new AuditService())->log($request, 'create', 'allergy', (int) $allergy['id'], null, $data, $id);

        $this->created(['allergy' => $allergy]);
    }

    public function removeAllergy(Request $request): never
    {
        $id = $request->intParam('id');
        PatientService::for($request)->removeAllergy($id, $request->intParam('allergyId'));

        (new AuditService())->log(
            $request, 'update', 'allergy', $request->intParam('allergyId'),
            null, ['is_active' => 0], $id,
        );

        $this->ok(['message' => 'Allergy marked inactive']);
    }

    // ---- conditions ----

    public function conditions(Request $request): never
    {
        $id = $request->intParam('id');
        PatientService::for($request)->assertMayAccess($request, $id);

        $this->ok(['conditions' => PatientService::for($request)->conditions($id)]);
    }

    public function addCondition(Request $request): never
    {
        $data = $this->validate($request, [
            'name'         => 'required|string|max:200',
            'icd10_code'   => 'nullable|string|max:16',
            'status'       => 'nullable|in:active,resolved,chronic',
            'diagnosed_on' => 'nullable|date',
            'notes'        => 'nullable|string|max:2000',
        ]);

        $id        = $request->intParam('id');
        $condition = PatientService::for($request)->addCondition($id, $data);

        (new AuditService())->log($request, 'create', 'medical_condition', (int) $condition['id'], null, $data, $id);

        $this->created(['condition' => $condition]);
    }

    public function updateCondition(Request $request): never
    {
        $data = $this->validate($request, ['status' => 'required|in:active,resolved,chronic']);

        $id = $request->intParam('id');
        PatientService::for($request)
            ->setConditionStatus($id, $request->intParam('conditionId'), (string) $data['status']);

        (new AuditService())->log(
            $request, 'update', 'medical_condition', $request->intParam('conditionId'),
            null, $data, $id,
        );

        $this->ok(['message' => 'Condition updated']);
    }
}
