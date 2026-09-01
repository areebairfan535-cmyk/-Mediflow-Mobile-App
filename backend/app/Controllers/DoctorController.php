<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\ValidationException;
use App\Repositories\DoctorRepository;
use App\Services\AppointmentService;
use App\Services\AuditService;

final class DoctorController extends Controller
{
    private function repo(Request $request): DoctorRepository
    {
        return (new DoctorRepository())->forOrganization($request->organizationId());
    }

    public function index(Request $request): never
    {
        $q = $this->validateQuery($request, [
            'specialty' => 'nullable|string|max:120',
            'accepting' => 'nullable|boolean',
        ]);

        $this->ok([
            'doctors'     => $this->repo($request)->listWithUser(
                $q['specialty'] ?? null,
                $q['accepting'] ?? null,
            ),
            'specialties' => $this->repo($request)->specialties(),
        ]);
    }

    public function show(Request $request): never
    {
        $doctor = $this->repo($request)->findWithUser($request->intParam('id'));
        if ($doctor === null) {
            throw new NotFoundException('Doctor not found');
        }

        $doctor['schedule'] = $this->repo($request)->schedule((int) $doctor['id']);

        $this->ok(['doctor' => $doctor]);
    }

    /**
     * Attach a clinical profile to an existing member. The user must already
     * be in the organization — this endpoint grants a profile, not access.
     */
    public function store(Request $request): never
    {
        $data = $this->validate($request, [
            'user_id'          => 'required|integer',
            'specialty'        => 'required|string|max:120',
            'qualification'    => 'nullable|string|max:255',
            'license_no'       => 'nullable|string|max:64',
            'experience_years' => 'nullable|integer|between:0,70',
            'consultation_fee' => 'nullable|numeric|min:0',
            'followup_fee'     => 'nullable|numeric|min:0',
            'bio'              => 'nullable|string|max:2000',
            'room'             => 'nullable|string|max:60',
            'slot_minutes'     => 'nullable|integer|between:5,240',
        ]);

        // §22: a doctor is a paid seat, so the plan decides before anything is
        // written — and before a temporary account could be left behind.
        \App\Services\SubscriptionService::for($request)->assertWithin('doctors');

        $repo = $this->repo($request);

        if ($repo->exists(['user_id' => (int) $data['user_id']])) {
            throw new \App\Core\ConflictException('This member already has a doctor profile.');
        }

        $membership = (new \App\Services\RbacService())
            ->membership((int) $data['user_id'], (int) $request->organizationId());

        if ($membership === null) {
            throw new ValidationException(
                ['user_id' => ['That user is not a member of this organization. Add them to the team first.']]
            );
        }

        $doctor = $repo->create($data + ['created_at' => now(), 'updated_at' => now()]);

        (new AuditService())->log($request, 'create', 'doctor', (int) $doctor['id'], null, $data);

        $this->created(['doctor' => $repo->findWithUser((int) $doctor['id'])]);
    }

    public function update(Request $request): never
    {
        $data = $this->validate($request, [
            'specialty'        => 'nullable|string|max:120',
            'qualification'    => 'nullable|string|max:255',
            'license_no'       => 'nullable|string|max:64',
            'experience_years' => 'nullable|integer|between:0,70',
            'consultation_fee' => 'nullable|numeric|min:0',
            'followup_fee'     => 'nullable|numeric|min:0',
            'bio'              => 'nullable|string|max:2000',
            'room'             => 'nullable|string|max:60',
            'slot_minutes'     => 'nullable|integer|between:5,240',
            'is_accepting'     => 'nullable|boolean',
        ]);

        $id     = $request->intParam('id');
        $repo   = $this->repo($request);
        $before = $repo->findOrFail($id, 'Doctor');
        $after  = $repo->update($id, $data);

        (new AuditService())->logUpdate($request, 'doctor', $id, $before, $after);

        $this->ok(['doctor' => $repo->findWithUser($id)]);
    }

    public function schedule(Request $request): never
    {
        $id = $request->intParam('id');
        $this->repo($request)->findOrFail($id, 'Doctor');

        $this->ok(['schedule' => $this->repo($request)->schedule($id)]);
    }

    /** Body: { slots: [{day_of_week: 1-7, start_time, end_time}, ...] } */
    public function updateSchedule(Request $request): never
    {
        $slots = $request->body['slots'] ?? null;
        if (!is_array($slots)) {
            throw new ValidationException(['slots' => ['Expected a list of slots.']]);
        }

        $errors = [];
        foreach (array_values($slots) as $i => $slot) {
            $label = 'Slot ' . ($i + 1);
            $day   = (int) ($slot['day_of_week'] ?? 0);

            if ($day < 1 || $day > 7) {
                $errors[] = "$label: day_of_week must be 1 (Mon) to 7 (Sun).";
                continue;
            }
            foreach (['start_time', 'end_time'] as $key) {
                if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', (string) ($slot[$key] ?? ''))) {
                    $errors[] = "$label: $key must be HH:MM.";
                }
            }
            if (isset($slot['start_time'], $slot['end_time'])
                && strtotime((string) $slot['end_time']) <= strtotime((string) $slot['start_time'])
            ) {
                $errors[] = "$label: end_time must be after start_time.";
            }
        }

        if ($errors !== []) {
            throw new ValidationException(['slots' => $errors]);
        }

        $id   = $request->intParam('id');
        $repo = $this->repo($request);
        $repo->findOrFail($id, 'Doctor');

        $saved = $repo->replaceSchedule($id, $slots);

        (new AuditService())->log($request, 'update', 'doctor_schedule', $id, null, ['slots' => count($slots)]);

        $this->ok(['schedule' => $saved]);
    }

    public function availableSlots(Request $request): never
    {
        $q = $this->validateQuery($request, ['date' => 'required|date']);

        $this->ok([
            'date'  => $q['date'],
            'slots' => AppointmentService::for($request)
                ->availableSlots($request->intParam('id'), (string) $q['date']),
        ]);
    }

    /** Today's workload for the signed-in clinician (§4 doctor dashboard). */
    public function dashboard(Request $request): never
    {
        $doctor = $this->repo($request)->forUser((int) $request->userId());
        if ($doctor === null) {
            throw new NotFoundException('You do not have a doctor profile in this organization.');
        }

        $service = AppointmentService::for($request);

        // The service returns the day's counts with the money alongside them;
        // they are separate blocks in the response because they answer
        // different questions and the screen shows them in different places.
        $dashboard = $service->doctorDashboard((int) $doctor['id']);
        $money     = $dashboard['money'] ?? null;
        unset($dashboard['money']);

        $this->ok([
            'doctor'       => $doctor,
            'counts'       => $dashboard,
            'money'        => $money,
            'today'        => $service->calendar([
                'doctor_id' => (int) $doctor['id'],
                'date'      => $service->clinicToday(),
            ]),
            'open_encounter' => (new \App\Repositories\EncounterRepository())
                ->forOrganization($request->organizationId())
                ->openForDoctor((int) $doctor['id']),
        ]);
    }
}
