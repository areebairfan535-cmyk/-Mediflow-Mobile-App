<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ConflictException;
use App\Core\NotFoundException;
use App\Core\Service;
use App\Core\ValidationException;
use App\Repositories\ClinicalRepository;
use App\Repositories\EncounterRepository;
use App\Repositories\PatientRepository;
use App\Repositories\PrescriptionRepository;

/**
 * Digital prescriptions (§4): medicine, dosage, frequency, duration and
 * instructions, issued against an encounter.
 */
final class PrescriptionService extends Service
{
    private function prescriptions(): PrescriptionRepository
    {
        return (new PrescriptionRepository())->forOrganization($this->requireOrganization());
    }

    private function encounters(): EncounterRepository
    {
        return (new EncounterRepository())->forOrganization($this->requireOrganization());
    }

    private function clinical(): ClinicalRepository
    {
        return (new ClinicalRepository())->forOrganization($this->requireOrganization());
    }

    private function patients(): PatientRepository
    {
        return (new PatientRepository())->forOrganization($this->requireOrganization());
    }

    /** @return array<string,mixed> */
    public function show(int $id): array
    {
        $row = $this->prescriptions()->findDetailed($id);
        if ($row === null) {
            throw new NotFoundException('Prescription not found');
        }
        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function forPatient(int $patientId): array
    {
        $this->patients()->findOrFail($patientId, 'Patient');
        return $this->prescriptions()->forPatient($patientId);
    }

    /**
     * Create a prescription against an open encounter.
     *
     * @param array<string,mixed> $data encounter_id, items[], general_advice?
     * @return array{prescription: array<string,mixed>, warnings: list<string>}
     */
    public function create(array $data): array
    {
        $encounterId = (int) $data['encounter_id'];
        $encounter   = $this->encounters()->findOrFail($encounterId, 'Encounter');

        if ($encounter['status'] !== 'open') {
            throw new ConflictException(
                'Prescriptions can only be added to an open consultation.'
            );
        }

        $items = $this->normaliseItems($data['items'] ?? []);
        $warnings = $this->allergyWarnings((int) $encounter['patient_id'], $items);

        $repo = $this->prescriptions();

        $prescription = $this->transaction(function () use ($repo, $encounter, $encounterId, $data, $items): array {
            $created = $repo->create($this->stampCreate([
                'encounter_id'    => $encounterId,
                'patient_id'      => (int) $encounter['patient_id'],
                'doctor_id'       => (int) $encounter['doctor_id'],
                'prescription_no' => $repo->nextPrescriptionNo(),
                'status'          => 'draft',
                'general_advice'  => $data['general_advice'] ?? null,
            ]));

            $repo->replaceItems((int) $created['id'], $items);

            return $created;
        });

        return [
            'prescription' => $this->show((int) $prescription['id']),
            'warnings'     => $warnings,
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @return array{prescription: array<string,mixed>, warnings: list<string>}
     */
    public function update(int $id, array $data): array
    {
        $repo         = $this->prescriptions();
        $prescription = $repo->findOrFail($id, 'Prescription');

        if ($prescription['status'] !== 'draft') {
            throw new ConflictException(
                'An issued prescription cannot be edited. Cancel it and write a new one.'
            );
        }

        $warnings = [];

        $this->transaction(function () use ($repo, $id, $data, $prescription, &$warnings): void {
            if (array_key_exists('general_advice', $data)) {
                $repo->update($id, ['general_advice' => $data['general_advice']]);
            }
            if (array_key_exists('items', $data)) {
                $items    = $this->normaliseItems($data['items']);
                $warnings = $this->allergyWarnings((int) $prescription['patient_id'], $items);
                $repo->replaceItems($id, $items);
            }
        });

        return ['prescription' => $this->show($id), 'warnings' => $warnings];
    }

    /** Issue: the point the prescription becomes the patient's document. */
    public function issue(int $id): array
    {
        $repo         = $this->prescriptions();
        $prescription = $repo->findOrFail($id, 'Prescription');

        if ($prescription['status'] === 'issued') {
            throw new ConflictException('This prescription has already been issued.');
        }
        if ($prescription['status'] === 'cancelled') {
            throw new ConflictException('This prescription was cancelled.');
        }
        if ($repo->items($id) === []) {
            throw new ConflictException('Add at least one medicine before issuing.');
        }

        $repo->update($id, ['status' => 'issued', 'issued_at' => now()]);

        // §20: issuing is the moment the prescription becomes the patient's
        // document, so it is also the moment they are told about it.
        try {
            (new NotificationService($this->organizationId, $this->actorId))->notifyPatient(
                (int) $prescription['patient_id'],
                'prescription.issued',
                [
                    'doctor'       => $this->doctorName((int) $prescription['doctor_id']),
                    'count'        => count($repo->items($id)),
                    'subject_type' => 'prescription',
                    'subject_id'   => $id,
                ],
            );
        } catch (\Throwable $e) {
            error_log('[notify] prescription notification failed: ' . $e->getMessage());
        }

        return $this->show($id);
    }

    private function doctorName(int $doctorId): string
    {
        $row = \App\Core\Database::selectOne(
            'SELECT u.name FROM doctors d JOIN users u ON u.id = d.user_id WHERE d.id = :id',
            ['id' => $doctorId],
        );
        return (string) ($row['name'] ?? 'Your doctor');
    }

    public function cancel(int $id): array
    {
        $repo = $this->prescriptions();
        $repo->findOrFail($id, 'Prescription');
        $repo->update($id, ['status' => 'cancelled']);

        return $this->show($id);
    }

    /** @return list<array<string,mixed>> */
    public function medications(?string $search): array
    {
        return $this->clinical()->medications($search);
    }

    /**
     * @param mixed $items
     * @return list<array<string,mixed>>
     */
    private function normaliseItems(mixed $items): array
    {
        if (!is_array($items) || $items === []) {
            throw new ValidationException(['items' => ['Add at least one medicine.']]);
        }

        $out    = [];
        $errors = [];

        foreach (array_values($items) as $i => $item) {
            if (!is_array($item)) {
                $errors[] = "Item " . ($i + 1) . " is not valid.";
                continue;
            }
            $name = trim((string) ($item['medication_name'] ?? ''));
            if ($name === '') {
                $errors[] = 'Item ' . ($i + 1) . ' needs a medicine name.';
                continue;
            }
            $out[] = [
                'medication_id'   => isset($item['medication_id']) ? (int) $item['medication_id'] : null,
                'medication_name' => $name,
                'dosage'          => $this->trimOrNull($item['dosage'] ?? null),
                'frequency'       => $this->trimOrNull($item['frequency'] ?? null),
                'duration'        => $this->trimOrNull($item['duration'] ?? null),
                'instructions'    => $this->trimOrNull($item['instructions'] ?? null),
            ];
        }

        if ($errors !== []) {
            throw new ValidationException(['items' => $errors]);
        }

        return $out;
    }

    /**
     * Flag prescribed medicines that match a recorded allergy.
     *
     * Advisory, not blocking: a clinician may prescribe against a recorded
     * allergy for good reason, and the system must not silently override a
     * clinical decision. It returns warnings alongside the saved prescription
     * so the UI can surface them prominently.
     *
     * @param list<array<string,mixed>> $items
     * @return list<string>
     */
    private function allergyWarnings(int $patientId, array $items): array
    {
        $allergies = array_filter(
            $this->clinical()->allergies($patientId),
            static fn(array $a): bool => (int) $a['is_active'] === 1,
        );

        if ($allergies === []) {
            return [];
        }

        $warnings = [];

        foreach ($items as $item) {
            $name = mb_strtolower($item['medication_name']);
            foreach ($allergies as $allergy) {
                $substance = mb_strtolower(trim((string) $allergy['substance']));
                if ($substance === '') {
                    continue;
                }
                if (str_contains($name, $substance) || str_contains($substance, $name)) {
                    $warnings[] = sprintf(
                        'ALLERGY: %s — patient has a recorded %s allergy to "%s"%s.',
                        $item['medication_name'],
                        $allergy['severity'],
                        $allergy['substance'],
                        $allergy['reaction'] ? " ({$allergy['reaction']})" : '',
                    );
                }
            }
        }

        return array_values(array_unique($warnings));
    }

    private function trimOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }
}
