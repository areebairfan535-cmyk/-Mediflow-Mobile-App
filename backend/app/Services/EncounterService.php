<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ConflictException;
use App\Core\ForbiddenException;
use App\Core\NotFoundException;
use App\Core\Service;
use App\Repositories\AppointmentRepository;
use App\Repositories\ClinicalRepository;
use App\Repositories\DoctorRepository;
use App\Repositories\EncounterRepository;
use App\Repositories\PatientRepository;

/**
 * The consultation workflow (§4):
 *
 *   patient → medical history → previous visits → symptoms → diagnosis
 *   → prescription → lab/procedure → follow-up → invoice
 *
 * An encounter is opened, filled in over the course of the visit, then closed.
 * Closing is the point where the visit becomes billable, which is why
 * complete() is the only method that refuses on an empty record.
 */
final class EncounterService extends Service
{
    private function encounters(): EncounterRepository
    {
        return (new EncounterRepository())->forOrganization($this->requireOrganization());
    }

    private function appointments(): AppointmentRepository
    {
        return (new AppointmentRepository())->forOrganization($this->requireOrganization());
    }

    private function doctors(): DoctorRepository
    {
        return (new DoctorRepository())->forOrganization($this->requireOrganization());
    }

    private function patients(): PatientRepository
    {
        return (new PatientRepository())->forOrganization($this->requireOrganization());
    }

    private function clinical(): ClinicalRepository
    {
        return (new ClinicalRepository())->forOrganization($this->requireOrganization());
    }

    /** @return list<array<string,mixed>> */
    public function index(array $filters): array
    {
        return $this->encounters()->listDetailed($filters);
    }

    /** @return array<string,mixed> */
    public function show(int $id): array
    {
        $record = $this->encounters()->fullRecord($id);
        if ($record === null) {
            throw new NotFoundException('Encounter not found');
        }
        return $record;
    }

    /**
     * Start a consultation.
     *
     * Opened either from a booked appointment (the normal path — the
     * appointment moves to in_consultation with it) or standalone for a
     * walk-in.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function start(array $data): array
    {
        $encounters = $this->encounters();

        // Who the visit is for and with is decided FIRST — when an appointment
        // is given it is the source of truth for both, and the request body
        // carries neither.
        $appointment = null;

        if (!empty($data['appointment_id'])) {
            $appointment = $this->appointments()->findOrFail((int) $data['appointment_id'], 'Appointment');

            if (in_array($appointment['status'], ['cancelled', 'no_show', 'completed'], true)) {
                throw new ConflictException(
                    "Cannot start a consultation for a {$appointment['status']} appointment."
                );
            }

            $patientId = (int) $appointment['patient_id'];
            $doctorId  = (int) $appointment['doctor_id'];

            // One consultation per appointment. Checked against the encounters
            // table rather than a column on the appointment, so it holds even
            // if the two rows are written by different requests.
            if ($encounters->exists(['appointment_id' => (int) $appointment['id']])) {
                throw new ConflictException('This appointment already has a consultation.');
            }
        } else {
            $patientId = (int) ($data['patient_id'] ?? 0);
            $doctorId  = (int) ($data['doctor_id'] ?? 0);
        }

        $this->doctors()->findOrFail($doctorId, 'Doctor');
        $this->patients()->findOrFail($patientId, 'Patient');

        // One open consultation per doctor: two half-written charts is how
        // notes end up on the wrong patient.
        $open = $encounters->openForDoctor($doctorId);
        if ($open !== null) {
            throw new ConflictException(
                'You already have consultation ' . $open['encounter_no'] . ' open. '
                . 'Complete or cancel it first.'
            );
        }

        return $this->transaction(function () use ($encounters, $data, $patientId, $doctorId, $appointment): array {
            $encounter = $encounters->create($this->stampCreate([
                'patient_id'      => $patientId,
                'doctor_id'       => $doctorId,
                'appointment_id'  => $appointment !== null ? (int) $appointment['id'] : null,
                'encounter_no'    => $encounters->nextEncounterNo(),
                'type'            => $data['type'] ?? 'outpatient',
                'status'          => 'open',
                'chief_complaint' => $data['chief_complaint'] ?? ($appointment['reason'] ?? null),
                'started_at'      => now(),
            ]));

            if ($appointment !== null && $appointment['status'] !== 'in_consultation') {
                $this->appointments()->update((int) $appointment['id'], [
                    'status'     => 'in_consultation',
                    'updated_by' => $this->actorId,
                ]);
            }

            return $encounter;
        });
    }

    /**
     * Record findings. Only allowed while the encounter is open — a completed
     * chart is a clinical record, not a draft.
     *
     * @param array<string,mixed> $data
     * @return array{before: array<string,mixed>, after: array<string,mixed>}
     */
    public function update(int $id, array $data): array
    {
        $repo   = $this->encounters();
        $before = $repo->findOrFail($id, 'Encounter');

        $this->assertOpen($before);

        return ['before' => $before, 'after' => $repo->update($id, $this->stampUpdate($data))];
    }

    /**
     * Close the consultation and, if it came from an appointment, complete that too.
     *
     * @return array<string,mixed>
     */
    public function complete(int $id, ?string $followupOn = null): array
    {
        $repo      = $this->encounters();
        $encounter = $repo->findOrFail($id, 'Encounter');

        $this->assertOpen($encounter);

        // A visit with nothing recorded should not become a billable record.
        $record = $repo->fullRecord($id);
        $hasContent =
            ($record['diagnoses'] ?? []) !== []
            || ($record['procedures'] ?? []) !== []
            || ($record['prescriptions'] ?? []) !== []
            || !empty($encounter['examination'])
            || !empty($encounter['symptoms']);

        if (!$hasContent) {
            throw new ConflictException(
                'Record at least a diagnosis, examination or prescription before completing.'
            );
        }

        return $this->transaction(function () use ($repo, $id, $encounter, $followupOn): array {
            $completed = $repo->update($id, $this->stampUpdate([
                'status'       => 'completed',
                'completed_at' => now(),
                'followup_on'  => $followupOn,
            ]));

            if (!empty($encounter['appointment_id'])) {
                $appointment = $this->appointments()->find((int) $encounter['appointment_id']);
                if ($appointment !== null && $appointment['status'] === 'in_consultation') {
                    $this->appointments()->update((int) $appointment['id'], [
                        'status'     => 'completed',
                        'updated_by' => $this->actorId,
                    ]);
                }
            }

            return $completed;
        });
    }

    public function cancel(int $id, ?string $reason): array
    {
        $repo      = $this->encounters();
        $encounter = $repo->findOrFail($id, 'Encounter');

        $this->assertOpen($encounter);

        return $this->transaction(function () use ($repo, $id, $encounter, $reason): array {
            $cancelled = $repo->update($id, $this->stampUpdate(['status' => 'cancelled']));

            if (!empty($encounter['appointment_id'])) {
                $appointment = $this->appointments()->find((int) $encounter['appointment_id']);
                if ($appointment !== null && $appointment['status'] === 'in_consultation') {
                    $this->appointments()->update((int) $appointment['id'], [
                        'status'           => 'cancelled',
                        'cancelled_reason' => $reason ?? 'Consultation cancelled',
                        'updated_by'       => $this->actorId,
                    ]);
                }
            }

            return $cancelled;
        });
    }

    // ---- child records ----

    /** @param array<string,mixed> $data */
    public function addDiagnosis(int $encounterId, array $data): array
    {
        $encounter = $this->encounters()->findOrFail($encounterId, 'Encounter');
        $this->assertOpen($encounter);

        return $this->encounters()->addDiagnosis($data + [
            'encounter_id' => $encounterId,
            'patient_id'   => (int) $encounter['patient_id'],
            'created_by'   => $this->actorId,
        ]);
    }

    /** @param array<string,mixed> $data */
    public function addProcedure(int $encounterId, array $data): array
    {
        $encounter = $this->encounters()->findOrFail($encounterId, 'Encounter');
        $this->assertOpen($encounter);

        return $this->encounters()->addProcedure($data + [
            'encounter_id' => $encounterId,
            'patient_id'   => (int) $encounter['patient_id'],
            'doctor_id'    => (int) $encounter['doctor_id'],
            'performed_at' => $data['performed_at'] ?? now(),
            'created_by'   => $this->actorId,
        ]);
    }

    /** @param array<string,mixed> $data */
    public function addNote(int $encounterId, array $data): array
    {
        $encounter = $this->encounters()->findOrFail($encounterId, 'Encounter');
        $this->assertOpen($encounter);

        return $this->encounters()->addNote($data + [
            'encounter_id' => $encounterId,
            'patient_id'   => (int) $encounter['patient_id'],
            'created_by'   => $this->actorId,
        ]);
    }

    public function removeChild(string $kind, int $encounterId, int $childId): void
    {
        $table = match ($kind) {
            'diagnosis' => 'diagnoses',
            'procedure' => 'procedures',
            'note'      => 'clinical_notes',
            default     => throw new NotFoundException('Unknown record type'),
        };

        $encounter = $this->encounters()->findOrFail($encounterId, 'Encounter');
        $this->assertOpen($encounter);

        if (!$this->encounters()->deleteChild($table, $encounterId, $childId)) {
            throw new NotFoundException('Record not found');
        }
    }

    /** @param array<string,mixed> $data */
    public function orderLab(int $encounterId, array $data): array
    {
        $encounter = $this->encounters()->findOrFail($encounterId, 'Encounter');
        $this->assertOpen($encounter);

        return $this->clinical()->createLabOrder([
            'encounter_id'   => $encounterId,
            'patient_id'     => (int) $encounter['patient_id'],
            'doctor_id'      => (int) $encounter['doctor_id'],
            'priority'       => $data['priority'] ?? 'routine',
            'clinical_notes' => $data['clinical_notes'] ?? null,
        ], $this->actorId);
    }

    /** @param array<string,mixed> $encounter */
    private function assertOpen(array $encounter): void
    {
        if ($encounter['status'] !== 'open') {
            throw new ConflictException(
                "This consultation is {$encounter['status']} and can no longer be edited."
            );
        }
    }
}
