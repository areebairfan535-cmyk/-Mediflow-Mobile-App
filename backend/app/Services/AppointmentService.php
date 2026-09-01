<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ConflictException;
use App\Core\Database;
use App\Core\ForbiddenException;
use App\Core\NotFoundException;
use App\Core\Service;
use App\Core\ValidationException;
use App\Repositories\AppointmentRepository;
use App\Repositories\DoctorRepository;
use App\Repositories\PatientRepository;
use App\Services\Billing\Money;

/**
 * Booking rules (§3, §4).
 *
 * The rules that matter and are enforced here:
 *   - the slot must fall inside the doctor's weekly working window
 *   - it must not overlap another live appointment for that doctor
 *   - it must not be in the past
 *   - status may only move along legal transitions
 */
final class AppointmentService extends Service
{
    /** Which statuses each status may move to. */
    private const TRANSITIONS = [
        'booked'          => ['confirmed', 'arrived', 'cancelled', 'no_show'],
        'confirmed'       => ['arrived', 'cancelled', 'no_show'],
        'arrived'         => ['in_consultation', 'cancelled', 'no_show'],
        'in_consultation' => ['completed', 'cancelled'],
        'completed'       => [],
        'cancelled'       => [],
        'no_show'         => [],
    ];

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

    /**
     * The organization's timezone (§23).
     *
     * Everything is STORED in UTC, but a doctor's "09:00–13:00" is a wall-clock
     * time at that clinic, not an instant. So schedule windows are resolved in
     * this zone and converted to UTC before they are compared with bookings.
     * Without that, a Karachi clinic and a London clinic on one deployment
     * would silently mean different hours by the same numbers.
     */
    private function timezone(): \DateTimeZone
    {
        $settings = (new \App\Repositories\OrganizationRepository())
            ->settings($this->requireOrganization());

        try {
            return new \DateTimeZone((string) ($settings['timezone'] ?? 'UTC'));
        } catch (\Exception) {
            // A bad tz string in the row must not take booking down.
            return new \DateTimeZone('UTC');
        }
    }

    /** Today's date as the clinic reckons it. */
    public function clinicToday(): string
    {
        return (new \DateTimeImmutable('now', $this->timezone()))->format('Y-m-d');
    }

    /** Combine a clinic-local date and time into a UTC instant. */
    private function clinicLocalToUtc(string $date, string $time, \DateTimeZone $tz): \DateTimeImmutable
    {
        return (new \DateTimeImmutable("$date $time", $tz))
            ->setTimezone(new \DateTimeZone('UTC'));
    }

    /** @return list<array<string,mixed>> */
    public function calendar(array $filters): array
    {
        return $this->appointments()->calendar($filters, $this->timezone());
    }

    /** @return array<string,mixed> */
    public function show(int $id): array
    {
        $row = $this->appointments()->findDetailed($id);
        if ($row === null) {
            throw new NotFoundException('Appointment not found');
        }
        return $row;
    }

    /**
     * Free slots for a doctor on a date: the working windows, minus what is
     * already booked, in the doctor's own slot length.
     *
     * @return list<array{start:string, end:string}>
     */
    public function availableSlots(int $doctorId, string $date): array
    {
        $doctor = $this->doctors()->find($doctorId);
        if ($doctor === null) {
            throw new NotFoundException('Doctor not found');
        }

        $tz = $this->timezone();

        // The weekday is the clinic's weekday, which near midnight is not
        // necessarily UTC's.
        $dayOfWeek = (int) (new \DateTimeImmutable($date, $tz))->format('N');   // 1 Mon .. 7 Sun
        $windows   = $this->doctors()->windowsForDay($doctorId, $dayOfWeek);

        if ($windows === []) {
            return [];
        }

        $step   = max(5, (int) $doctor['slot_minutes']);
        $booked = $this->appointments()->bookedOn($doctorId, $date, $tz);
        $now    = time();

        $taken = array_map(
            static function (array $b): array {
                $start = strtotime((string) $b['scheduled_at'] . ' UTC');
                return [$start, $start + ((int) $b['duration_minutes'] * 60)];
            },
            $booked,
        );

        $slots = [];

        foreach ($windows as $window) {
            $cursor = $this->clinicLocalToUtc($date, (string) $window['start_time'], $tz)
                ->getTimestamp();
            $end = $this->clinicLocalToUtc($date, (string) $window['end_time'], $tz)
                ->getTimestamp();

            while ($cursor + ($step * 60) <= $end) {
                $slotStart = $cursor;
                $slotEnd   = $cursor + ($step * 60);
                $cursor    = $slotEnd;

                if ($slotStart <= $now) {
                    continue;   // never offer a slot in the past
                }

                foreach ($taken as [$bStart, $bEnd]) {
                    if ($slotStart < $bEnd && $bStart < $slotEnd) {
                        continue 2;
                    }
                }

                // Returned as UTC, which is what the client sends back on book().
                $slots[] = [
                    'start' => gmdate('Y-m-d H:i:s', $slotStart),
                    'end'   => gmdate('Y-m-d H:i:s', $slotEnd),
                ];
            }
        }

        return $slots;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function book(array $data): array
    {
        $this->plan()->assertWithin('appointments');          // §22

        $patient = $this->patients()->findOrFail((int) $data['patient_id'], 'Patient');
        $doctor  = $this->doctors()->findOrFail((int) $data['doctor_id'], 'Doctor');

        if ($patient['status'] !== 'active') {
            throw new ConflictException('That patient record is not active.');
        }
        if ((int) $doctor['is_accepting'] !== 1) {
            throw new ConflictException('This doctor is not accepting appointments.');
        }

        $startsAt = (string) $data['scheduled_at'];
        $minutes  = (int) ($data['duration_minutes'] ?? $doctor['slot_minutes']);

        $this->assertBookable((int) $doctor['id'], $startsAt, $minutes);

        $appointment = $this->appointments()->create($this->stampCreate([
            'patient_id'       => (int) $patient['id'],
            'doctor_id'        => (int) $doctor['id'],
            'scheduled_at'     => $startsAt,
            'duration_minutes' => $minutes,
            'type'             => $data['type'] ?? 'consultation',
            'status'           => 'booked',
            'reason'           => $data['reason'] ?? null,
            'booked_by'        => $this->actorId,
        ]));

        $this->notify($appointment, 'appointment.booked');

        // §20 lists an appointment reminder as its own event. Queue it now,
        // scheduled for the day before — the dispatcher sends what is due.
        $reminderAt = (new \DateTimeImmutable($startsAt, new \DateTimeZone('UTC')))
            ->modify('-1 day');

        if ($reminderAt->getTimestamp() > time()) {
            $this->notify(
                $appointment,
                'appointment.reminder',
                ['scheduled_for' => $reminderAt->format('Y-m-d H:i:s')],
            );
        }

        return $appointment;
    }

    /**
     * Queue a patient notification about an appointment.
     *
     * Wrapped so a notification failure can never break a booking — the
     * appointment is the transaction that matters.
     *
     * @param array<string,mixed> $appointment
     * @param array<string,mixed> $extra
     */
    private function notify(array $appointment, string $event, array $extra = []): void
    {
        try {
            $doctor = \App\Core\Database::selectOne(
                'SELECT u.name FROM doctors d JOIN users u ON u.id = d.user_id WHERE d.id = :id',
                ['id' => (int) $appointment['doctor_id']],
            );

            $when = (new \DateTimeImmutable(
                (string) $appointment['scheduled_at'],
                new \DateTimeZone('UTC'),
            ))->setTimezone($this->timezone())->format('D d M, H:i');

            (new NotificationService($this->organizationId, $this->actorId))->notifyPatient(
                (int) $appointment['patient_id'],
                $event,
                [
                    'doctor'       => $doctor['name'] ?? 'your doctor',
                    'when'         => $when,
                    'reason'       => $appointment['cancelled_reason'] ?? '',
                    'subject_type' => 'appointment',
                    'subject_id'   => (int) $appointment['id'],
                ] + $extra,
            );
        } catch (\Throwable $e) {
            error_log('[notify] appointment notification failed: ' . $e->getMessage());
        }
    }

    /**
     * Reschedule = cancel-and-rebook, keeping the link. The original row is
     * retained rather than mutated so the history shows the move happened.
     *
     * @return array<string,mixed>
     */
    public function reschedule(int $id, string $startsAt, ?int $minutes, ?string $reason): array
    {
        $repo        = $this->appointments();
        $appointment = $repo->findOrFail($id, 'Appointment');

        if (in_array($appointment['status'], ['completed', 'cancelled', 'no_show'], true)) {
            throw new ConflictException(
                "A {$appointment['status']} appointment cannot be rescheduled."
            );
        }

        $duration = $minutes ?? (int) $appointment['duration_minutes'];
        $this->assertBookable((int) $appointment['doctor_id'], $startsAt, $duration, $id);

        return $this->transaction(function () use ($repo, $appointment, $id, $startsAt, $duration, $reason): array {
            $repo->update($id, $this->stampUpdate([
                'status'           => 'cancelled',
                'cancelled_reason' => $reason ?? 'Rescheduled',
            ]));

            return $repo->create($this->stampCreate([
                'patient_id'       => (int) $appointment['patient_id'],
                'doctor_id'        => (int) $appointment['doctor_id'],
                'scheduled_at'     => $startsAt,
                'duration_minutes' => $duration,
                'type'             => $appointment['type'],
                'status'           => 'booked',
                'reason'           => $appointment['reason'],
                'rescheduled_from' => $id,
                'booked_by'        => $this->actorId,
            ]));
        });
    }

    /** @return array{before: array<string,mixed>, after: array<string,mixed>} */
    public function changeStatus(int $id, string $status, ?string $reason = null): array
    {
        $repo   = $this->appointments();
        $before = $repo->findOrFail($id, 'Appointment');
        $from   = (string) $before['status'];

        $allowed = self::TRANSITIONS[$from] ?? [];

        if (!in_array($status, $allowed, true)) {
            throw new ConflictException(
                $allowed === []
                    ? "This appointment is already $from and cannot change."
                    : "Cannot go from $from to $status. Allowed: " . implode(', ', $allowed) . '.'
            );
        }

        $patch = ['status' => $status];
        if ($status === 'cancelled') {
            $patch['cancelled_reason'] = $reason;
        }

        $after = $repo->update($id, $this->stampUpdate($patch));

        if ($status === 'cancelled') {
            $this->notify($after, 'appointment.cancelled');
        }

        return ['before' => $before, 'after' => $after];
    }

    /** @return array<string,int> */
    /**
     * The doctor's day (§4): today's list, who is waiting, what is done — and
     * the money, which §4 asks for and a clinician does look at.
     *
     * "Revenue" here means what this doctor's own visits billed and collected,
     * not the clinic's whole ledger: a doctor sitting in one room should not
     * have to read the practice's accounts to see how their morning went.
     *
     * @return array<string,mixed>
     */
    public function doctorDashboard(int $doctorId): array
    {
        $dashboard = $this->appointments()->dashboardCounts($doctorId, $this->timezone());

        $today = (new \DateTimeImmutable('now', $this->timezone()))->format('Y-m-d');

        // Half-open UTC range for the clinic's local day, the same way every
        // other day-bounded query in this file does it (§23).
        $from = (new \DateTimeImmutable($today . ' 00:00:00', $this->timezone()))
            ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $to   = (new \DateTimeImmutable($today . ' 00:00:00', $this->timezone()))
            ->modify('+1 day')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $money = Database::selectOne(
            'SELECT
                COALESCE(SUM(CASE WHEN i.created_at >= :from AND i.created_at < :to
                                  THEN i.grand_total END), 0) AS billed_today,
                COALESCE(SUM(CASE WHEN i.created_at >= :from2 AND i.created_at < :to2
                                  THEN i.paid_total END), 0)  AS collected_today,
                COALESCE(SUM(CASE WHEN i.status NOT IN (\'cancelled\', \'draft\')
                                  THEN i.balance_due END), 0) AS outstanding
               FROM invoices i
               JOIN encounters e ON e.id = i.encounter_id
              WHERE i.organization_id = :org
                AND e.doctor_id = :doctor',
            [
                'org'    => $this->requireOrganization(),
                'doctor' => $doctorId,
                'from'   => $from,  'to'  => $to,
                'from2'  => $from,  'to2' => $to,
            ],
        ) ?? [];

        $dashboard['money'] = [
            'billed_today'    => Money::round($money['billed_today'] ?? 0),
            'collected_today' => Money::round($money['collected_today'] ?? 0),
            // Everything this doctor's visits have billed and not been paid —
            // not only today's, because that is what "outstanding" means.
            'outstanding'     => Money::round($money['outstanding'] ?? 0),
        ];

        return $dashboard;
    }

    /**
     * Validate a proposed slot against the past, the doctor's schedule and
     * existing bookings. Shared by book() and reschedule() so the two can
     * never drift apart.
     */
    private function assertBookable(
        int $doctorId,
        string $startsAt,
        int $minutes,
        ?int $excludeId = null,
    ): void {
        // Incoming times are UTC — that is what availableSlots() hands the client.
        $start = strtotime($startsAt . ' UTC');
        if ($start === false) {
            throw new ValidationException(['scheduled_at' => ['Not a valid date and time.']]);
        }
        if ($minutes < 5 || $minutes > 480) {
            throw new ValidationException(['duration_minutes' => ['Must be between 5 and 480 minutes.']]);
        }
        if ($start < time()) {
            throw new ValidationException(['scheduled_at' => ['Cannot book a time in the past.']]);
        }

        $tz = $this->timezone();

        // Which clinic-local day and weekday does this instant fall on?
        $localStart = (new \DateTimeImmutable('@' . $start))->setTimezone($tz);
        $dayOfWeek  = (int) $localStart->format('N');
        $dayName    = $localStart->format('l');
        $date       = $localStart->format('Y-m-d');

        $windows = $this->doctors()->windowsForDay($doctorId, $dayOfWeek);

        if ($windows === []) {
            throw new ConflictException("This doctor does not work on $dayName.");
        }

        $slotEnd = $start + ($minutes * 60);
        $inside  = false;

        foreach ($windows as $window) {
            $wStart = $this->clinicLocalToUtc($date, (string) $window['start_time'], $tz)->getTimestamp();
            $wEnd   = $this->clinicLocalToUtc($date, (string) $window['end_time'], $tz)->getTimestamp();
            if ($start >= $wStart && $slotEnd <= $wEnd) {
                $inside = true;
                break;
            }
        }

        if (!$inside) {
            $hours = implode(', ', array_map(
                static fn(array $w): string =>
                    substr((string) $w['start_time'], 0, 5) . '–' . substr((string) $w['end_time'], 0, 5),
                $windows,
            ));
            throw new ConflictException(
                "Outside the doctor's hours on $dayName ($hours clinic time)."
            );
        }

        if ($this->appointments()->hasConflict($doctorId, $startsAt, $minutes, $excludeId)) {
            throw new ConflictException('That slot overlaps another appointment for this doctor.');
        }
    }
}
