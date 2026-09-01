<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ConflictException;
use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\Service;
use App\Repositories\ClinicalRepository;
use App\Repositories\PatientRepository;

final class PatientService extends Service
{
    private function patients(): PatientRepository
    {
        return (new PatientRepository())->forOrganization($this->requireOrganization());
    }

    private function clinical(): ClinicalRepository
    {
        return (new ClinicalRepository())->forOrganization($this->requireOrganization());
    }

    /** @return array{data: list<array<string,mixed>>, meta: array<string,int>} */
    public function search(?string $term, ?string $status, int $page, int $perPage): array
    {
        return $this->patients()->search($term, $status, $page, $perPage);
    }

    /** @return array<string,mixed> */
    public function show(int $id): array
    {
        $patient = $this->patients()->withClinicalSummary($id);
        if ($patient === null) {
            throw new NotFoundException('Patient not found');
        }
        return $patient;
    }

    /**
     * Register a patient. The MRN is allocated inside the transaction, so a
     * failure anywhere leaves no gap in the sequence.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function register(array $data): array
    {
        // §22: the plan's ceiling, checked before anything is written.
        $this->plan()->assertWithin('patients');

        $repo = $this->patients();

        // A duplicate phone in the same clinic is nearly always the same person
        // being registered twice. Block it rather than silently splitting one
        // patient's history across two records.
        if (!empty($data['phone'])
            && $repo->exists(['phone' => $data['phone'], 'status' => 'active'])
        ) {
            throw new ConflictException(
                'A patient with this phone number already exists in this clinic.'
            );
        }

        return $this->transaction(function () use ($repo, $data): array {
            $payload = $this->stampCreate($data);
            $payload['mrn']    = $data['mrn'] ?? $repo->nextMrn();
            $payload['status'] = 'active';

            return $repo->create($payload);
        });
    }

    /**
     * @param array<string,mixed> $data
     * @return array{before: array<string,mixed>, after: array<string,mixed>}
     */
    public function update(int $id, array $data): array
    {
        $repo   = $this->patients();
        $before = $repo->findOrFail($id, 'Patient');
        $after  = $repo->update($id, $this->stampUpdate($data));

        return ['before' => $before, 'after' => $after];
    }

    public function deactivate(int $id): array
    {
        $repo = $this->patients();
        $repo->findOrFail($id, 'Patient');

        // Clinical records are never deleted — the row is marked inactive so
        // its history stays intact and auditable (§16).
        return $repo->update($id, $this->stampUpdate(['status' => 'inactive']));
    }

    // ---- allergies & conditions ----

    /** @return list<array<string,mixed>> */
    public function allergies(int $patientId): array
    {
        $this->patients()->findOrFail($patientId, 'Patient');
        return $this->clinical()->allergies($patientId);
    }

    /** @param array<string,mixed> $data */
    public function addAllergy(int $patientId, array $data): array
    {
        $this->patients()->findOrFail($patientId, 'Patient');
        return $this->clinical()->addAllergy($patientId, $data, $this->actorId);
    }

    public function removeAllergy(int $patientId, int $allergyId): void
    {
        if (!$this->clinical()->deactivateAllergy($patientId, $allergyId)) {
            throw new NotFoundException('Allergy not found');
        }
    }

    /** @return list<array<string,mixed>> */
    public function conditions(int $patientId): array
    {
        $this->patients()->findOrFail($patientId, 'Patient');
        return $this->clinical()->conditions($patientId);
    }

    /** @param array<string,mixed> $data */
    public function addCondition(int $patientId, array $data): array
    {
        $this->patients()->findOrFail($patientId, 'Patient');
        return $this->clinical()->addCondition($patientId, $data, $this->actorId);
    }

    public function setConditionStatus(int $patientId, int $conditionId, string $status): void
    {
        if (!$this->clinical()->setConditionStatus($patientId, $conditionId, $status)) {
            throw new NotFoundException('Condition not found');
        }
    }

    /**
     * Ownership check for the patient-facing app: a `patient` role may only
     * ever read its own chart. Role permissions alone cannot express this,
     * because every patient holds the same permission slugs — the distinction
     * is *which row*, so it belongs here in the service (§11 policy-based).
     */
    public function assertMayAccess(Request $request, int $patientId): void
    {
        if ($request->roleSlug() !== 'patient') {
            return;   // clinic staff are gated by permissions on the route
        }

        $own = $this->patients()->forUser((int) $request->userId());

        if ($own === null || (int) $own['id'] !== $patientId) {
            // 404, not 403: confirming the record exists would itself leak.
            throw new NotFoundException('Patient not found');
        }
    }
}
