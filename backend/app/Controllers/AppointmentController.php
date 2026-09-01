<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Services\AppointmentService;
use App\Services\AuditService;

final class AppointmentController extends Controller
{
    public function index(Request $request): never
    {
        $filters = $this->validateQuery($request, [
            'date'       => 'nullable|date',
            'from'       => 'nullable|date',
            'to'         => 'nullable|date',
            'doctor_id'  => 'nullable|integer',
            'patient_id' => 'nullable|integer',
            'status'     => 'nullable|in:booked,confirmed,arrived,in_consultation,completed,cancelled,no_show',
        ]);

        // With no filter at all this would return the clinic's entire history;
        // default to today, which is what a calendar view wants anyway.
        if ($filters === []) {
            $filters = ['date' => AppointmentService::for($request)->clinicToday()];
        }

        $this->ok(['appointments' => AppointmentService::for($request)->calendar($filters)]);
    }

    public function show(Request $request): never
    {
        $this->ok(['appointment' => AppointmentService::for($request)->show($request->intParam('id'))]);
    }

    public function store(Request $request): never
    {
        $data = $this->validate($request, [
            'patient_id'       => 'required|integer',
            'doctor_id'        => 'required|integer',
            'scheduled_at'     => 'required|datetime',
            'duration_minutes' => 'nullable|integer|between:5,480',
            'type'             => 'nullable|in:consultation,followup,procedure,teleconsult',
            'reason'           => 'nullable|string|max:500',
        ]);

        $appointment = AppointmentService::for($request)->book($data);

        (new AuditService())->log(
            $request, 'create', 'appointment', (int) $appointment['id'], null,
            ['scheduled_at' => $appointment['scheduled_at'], 'doctor_id' => $appointment['doctor_id']],
            (int) $appointment['patient_id'],
        );

        $this->created(['appointment' => AppointmentService::for($request)->show((int) $appointment['id'])]);
    }

    public function reschedule(Request $request): never
    {
        $data = $this->validate($request, [
            'scheduled_at'     => 'required|datetime',
            'duration_minutes' => 'nullable|integer|between:5,480',
            'reason'           => 'nullable|string|max:500',
        ]);

        $id = $request->intParam('id');

        $moved = AppointmentService::for($request)->reschedule(
            $id,
            (string) $data['scheduled_at'],
            isset($data['duration_minutes']) ? (int) $data['duration_minutes'] : null,
            $data['reason'] ?? null,
        );

        (new AuditService())->log(
            $request, 'update', 'appointment', $id, null,
            ['rescheduled_to' => $moved['id'], 'scheduled_at' => $moved['scheduled_at']],
            (int) $moved['patient_id'],
        );

        $this->created(['appointment' => AppointmentService::for($request)->show((int) $moved['id'])]);
    }

    public function changeStatus(Request $request): never
    {
        $data = $this->validate($request, [
            'status' => 'required|in:confirmed,arrived,in_consultation,completed,cancelled,no_show',
            'reason' => 'nullable|string|max:500',
        ]);

        $id     = $request->intParam('id');
        $result = AppointmentService::for($request)
            ->changeStatus($id, (string) $data['status'], $data['reason'] ?? null);

        (new AuditService())->logUpdate(
            $request, 'appointment', $id, $result['before'], $result['after'],
            (int) $result['after']['patient_id'],
        );

        $this->ok(['appointment' => AppointmentService::for($request)->show($id)]);
    }
}
