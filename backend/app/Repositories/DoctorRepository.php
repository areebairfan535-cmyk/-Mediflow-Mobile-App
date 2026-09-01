<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Repository;

final class DoctorRepository extends Repository
{
    protected string $table = 'doctors';

    protected array $fillable = [
        'user_id', 'specialty', 'qualification', 'license_no', 'experience_years',
        'consultation_fee', 'followup_fee', 'bio', 'room', 'slot_minutes',
        'is_accepting', 'created_at', 'updated_at',
    ];

    /** @return list<array<string,mixed>> */
    public function listWithUser(?string $specialty = null, ?bool $acceptingOnly = null): array
    {
        $where    = ['d.organization_id = :org'];
        $bindings = ['org' => $this->scopeBinding()];

        if ($specialty !== null && $specialty !== '') {
            $where[]              = 'd.specialty = :spec';
            $bindings['spec']     = $specialty;
        }
        if ($acceptingOnly === true) {
            $where[] = 'd.is_accepting = 1';
        }

        return Database::select(
            'SELECT d.*, u.name, u.email, u.phone, u.avatar_path, u.status AS user_status
               FROM doctors d
               JOIN users u ON u.id = d.user_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY u.name',
            $bindings,
        );
    }

    public function findWithUser(int $doctorId): ?array
    {
        return Database::selectOne(
            'SELECT d.*, u.name, u.email, u.phone, u.avatar_path
               FROM doctors d
               JOIN users u ON u.id = d.user_id
              WHERE d.organization_id = :org AND d.id = :id',
            ['org' => $this->scopeBinding(), 'id' => $doctorId],
        );
    }

    /** The doctor row for a logged-in clinician, or null if they are not one. */
    public function forUser(int $userId): ?array
    {
        return $this->firstWhere(['user_id' => $userId]);
    }

    /** @return list<string> */
    public function specialties(): array
    {
        $rows = Database::select(
            'SELECT DISTINCT specialty FROM doctors
              WHERE organization_id = :org ORDER BY specialty',
            ['org' => $this->scopeBinding()],
        );
        return array_column($rows, 'specialty');
    }

    // ---- weekly availability ----

    /** @return list<array<string,mixed>> */
    public function schedule(int $doctorId): array
    {
        return Database::select(
            'SELECT * FROM doctor_schedules
              WHERE organization_id = :org AND doctor_id = :did
              ORDER BY day_of_week, start_time',
            ['org' => $this->scopeBinding(), 'did' => $doctorId],
        );
    }

    /**
     * Replace a doctor's whole weekly template atomically. Partial application
     * would leave a schedule that matches neither the old nor the new intent.
     *
     * @param list<array<string,mixed>> $slots
     * @return list<array<string,mixed>>
     */
    public function replaceSchedule(int $doctorId, array $slots): array
    {
        $org = $this->scopeBinding();

        return Database::transaction(function () use ($org, $doctorId, $slots): array {
            Database::statement(
                'DELETE FROM doctor_schedules WHERE organization_id = :org AND doctor_id = :did',
                ['org' => $org, 'did' => $doctorId],
            );

            foreach ($slots as $slot) {
                Database::statement(
                    'INSERT INTO doctor_schedules
                        (organization_id, doctor_id, day_of_week, start_time, end_time,
                         is_active, created_at, updated_at)
                     VALUES (:org, :did, :dow, :start, :end, :active, :now, :now)',
                    [
                        'org'    => $org,
                        'did'    => $doctorId,
                        'dow'    => (int) $slot['day_of_week'],
                        'start'  => $slot['start_time'],
                        'end'    => $slot['end_time'],
                        'active' => isset($slot['is_active']) ? (int) (bool) $slot['is_active'] : 1,
                        'now'    => now(),
                    ],
                );
            }

            return $this->schedule($doctorId);
        });
    }

    /** Working windows for one weekday. @return list<array<string,mixed>> */
    public function windowsForDay(int $doctorId, int $dayOfWeek): array
    {
        return Database::select(
            'SELECT start_time, end_time FROM doctor_schedules
              WHERE organization_id = :org AND doctor_id = :did
                AND day_of_week = :dow AND is_active = 1
              ORDER BY start_time',
            ['org' => $this->scopeBinding(), 'did' => $doctorId, 'dow' => $dayOfWeek],
        );
    }
}
