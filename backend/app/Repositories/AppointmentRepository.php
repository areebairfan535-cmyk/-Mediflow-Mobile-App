<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Repository;

final class AppointmentRepository extends Repository
{
    protected string $table = 'appointments';

    protected array $fillable = [
        'patient_id', 'doctor_id', 'scheduled_at', 'duration_minutes', 'type',
        'status', 'reason', 'cancelled_reason', 'rescheduled_from', 'booked_by',
        'created_by', 'updated_by', 'created_at', 'updated_at',
    ];

    /** Statuses that still occupy the doctor's calendar. */
    private const BLOCKING = ['booked', 'confirmed', 'arrived', 'in_consultation'];

    /**
     * Does this booking overlap an existing one for the same doctor?
     *
     * Overlap, not equality: a 30-minute appointment starting at 10:15 must
     * clash with a 15-minute one at 10:00. Two intervals overlap when each
     * starts before the other ends.
     *
     * $excludeId lets a reschedule ignore the row being moved.
     */
    public function hasConflict(
        int $doctorId,
        string $startsAt,
        int $minutes,
        ?int $excludeId = null,
    ): bool {
        $bindings = [
            'org'   => $this->scopeBinding(),
            'did'   => $doctorId,
            'start' => $startsAt,
            'mins'  => $minutes,
        ];

        $exclude = '';
        if ($excludeId !== null) {
            $exclude            = ' AND id <> :exclude';
            $bindings['exclude'] = $excludeId;
        }

        $statuses = "'" . implode("','", self::BLOCKING) . "'";

        $row = Database::selectOne(
            'SELECT id FROM appointments
              WHERE organization_id = :org
                AND doctor_id       = :did
                AND status IN (' . $statuses . ')'
            . $exclude . '
                AND scheduled_at < DATE_ADD(:start, INTERVAL :mins MINUTE)
                AND DATE_ADD(scheduled_at, INTERVAL duration_minutes MINUTE) > :start
              LIMIT 1',
            $bindings,
        );

        return $row !== null;
    }

    /**
     * Appointments already taking time on a given CLINIC-LOCAL day.
     *
     * Rows are stored in UTC, so the day is expressed as a UTC range rather
     * than `DATE(scheduled_at) = :d` — in a UTC+5 clinic an 02:00 local
     * appointment lives on the previous UTC date, and a plain DATE() compare
     * would miss it and allow a double-booking.
     *
     * @return list<array<string,mixed>>
     */
    public function bookedOn(int $doctorId, string $date, ?\DateTimeZone $tz = null): array
    {
        $tz  = $tz ?? new \DateTimeZone('UTC');
        $utc = new \DateTimeZone('UTC');

        $from = (new \DateTimeImmutable($date . ' 00:00:00', $tz))
            ->setTimezone($utc)->format('Y-m-d H:i:s');
        $to = (new \DateTimeImmutable($date . ' 00:00:00', $tz))
            ->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');

        $statuses = "'" . implode("','", self::BLOCKING) . "'";

        return Database::select(
            'SELECT scheduled_at, duration_minutes FROM appointments
              WHERE organization_id = :org AND doctor_id = :did
                AND scheduled_at >= :from
                AND scheduled_at <  :to
                AND status IN (' . $statuses . ')
              ORDER BY scheduled_at',
            ['org' => $this->scopeBinding(), 'did' => $doctorId, 'from' => $from, 'to' => $to],
        );
    }

    /**
     * Calendar list with patient and doctor names attached.
     *
     * @param array<string,mixed> $filters date, from, to, doctor_id, patient_id, status
     * @return list<array<string,mixed>>
     */
    public function calendar(array $filters, ?\DateTimeZone $tz = null): array
    {
        $tz       = $tz ?? new \DateTimeZone('UTC');
        $utc      = new \DateTimeZone('UTC');
        $where    = ['a.organization_id = :org'];
        $bindings = ['org' => $this->scopeBinding()];

        // Dates from the client are clinic-local days; rows are UTC instants.
        // Convert to a half-open UTC range so a day near the zone boundary
        // shows the right appointments.
        $localDayStart = static fn(string $d): string =>
            (new \DateTimeImmutable($d . ' 00:00:00', $tz))->setTimezone($utc)->format('Y-m-d H:i:s');
        $localDayEnd = static fn(string $d): string =>
            (new \DateTimeImmutable($d . ' 00:00:00', $tz))
                ->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');

        if (!empty($filters['date'])) {
            $where[]          = 'a.scheduled_at >= :dfrom AND a.scheduled_at < :dto';
            $bindings['dfrom'] = $localDayStart((string) $filters['date']);
            $bindings['dto']   = $localDayEnd((string) $filters['date']);
        }
        if (!empty($filters['from'])) {
            $where[]          = 'a.scheduled_at >= :from';
            $bindings['from'] = $localDayStart((string) $filters['from']);
        }
        if (!empty($filters['to'])) {
            $where[]        = 'a.scheduled_at < :to';
            $bindings['to'] = $localDayEnd((string) $filters['to']);
        }
        if (!empty($filters['doctor_id'])) {
            $where[]           = 'a.doctor_id = :did';
            $bindings['did']   = (int) $filters['doctor_id'];
        }
        if (!empty($filters['patient_id'])) {
            $where[]           = 'a.patient_id = :pid';
            $bindings['pid']   = (int) $filters['patient_id'];
        }
        if (!empty($filters['status'])) {
            $where[]              = 'a.status = :status';
            $bindings['status']   = $filters['status'];
        }

        return Database::select(
            'SELECT a.*,
                    CONCAT(p.first_name, \' \', p.last_name) AS patient_name,
                    p.mrn, p.phone AS patient_phone,
                    u.name AS doctor_name, d.specialty, d.room,
                    e.id AS encounter_id, e.status AS encounter_status
               FROM appointments a
               JOIN patients p ON p.id = a.patient_id
               JOIN doctors  d ON d.id = a.doctor_id
               JOIN users    u ON u.id = d.user_id
               LEFT JOIN encounters e ON e.appointment_id = a.id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY a.scheduled_at',
            $bindings,
        );
    }

    public function findDetailed(int $id): ?array
    {
        return Database::selectOne(
            'SELECT a.*,
                    CONCAT(p.first_name, \' \', p.last_name) AS patient_name,
                    p.mrn, p.phone AS patient_phone,
                    u.name AS doctor_name, d.specialty,
                    e.id AS encounter_id
               FROM appointments a
               JOIN patients p ON p.id = a.patient_id
               JOIN doctors  d ON d.id = a.doctor_id
               JOIN users    u ON u.id = d.user_id
               LEFT JOIN encounters e ON e.appointment_id = a.id
              WHERE a.organization_id = :org AND a.id = :id',
            ['org' => $this->scopeBinding(), 'id' => $id],
        );
    }

    /**
     * Counters for the doctor dashboard (§4).
     *
     * "Today" and "this week" mean the clinic's today and week, so both are
     * expressed as UTC ranges derived from the organization's timezone rather
     * than compared with DATE()/YEARWEEK() on the stored UTC value.
     *
     * @return array<string,int>
     */
    public function dashboardCounts(int $doctorId, ?\DateTimeZone $tz = null): array
    {
        $tz  = $tz ?? new \DateTimeZone('UTC');
        $utc = new \DateTimeZone('UTC');

        $localNow  = new \DateTimeImmutable('now', $tz);
        $dayStart  = $localNow->setTime(0, 0)->setTimezone($utc);
        $dayEnd    = $localNow->setTime(0, 0)->modify('+1 day')->setTimezone($utc);
        // ISO weeks start Monday, matching YEARWEEK(..., 1).
        $weekStart = $localNow->setTime(0, 0)->modify('monday this week')->setTimezone($utc);
        $weekEnd   = $weekStart->modify('+7 days');

        $fmt = static fn(\DateTimeImmutable $d): string => $d->format('Y-m-d H:i:s');

        $row = Database::selectOne(
            'SELECT
               SUM(scheduled_at >= :ds AND scheduled_at < :de)                        AS today_total,
               SUM(scheduled_at >= :ds AND scheduled_at < :de AND status = \'arrived\')         AS waiting,
               SUM(scheduled_at >= :ds AND scheduled_at < :de AND status = \'in_consultation\') AS in_progress,
               SUM(scheduled_at >= :ds AND scheduled_at < :de AND status = \'completed\')       AS completed,
               SUM(scheduled_at >= :ds AND scheduled_at < :de
                   AND status IN (\'cancelled\',\'no_show\'))                          AS cancelled,
               SUM(scheduled_at >= :ws AND scheduled_at < :we)                        AS week_total
             FROM appointments
            WHERE organization_id = :org AND doctor_id = :did',
            [
                'org' => $this->scopeBinding(), 'did' => $doctorId,
                'ds' => $fmt($dayStart), 'de' => $fmt($dayEnd),
                'ws' => $fmt($weekStart), 'we' => $fmt($weekEnd),
            ],
        ) ?? [];

        return array_map(static fn($v): int => (int) $v, $row);
    }
}
