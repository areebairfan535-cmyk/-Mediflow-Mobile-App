<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Repository;
use App\Models\Staff;

/**
 * Employment records for the people who work at a clinic (§20).
 *
 * The `staff` table was created in the first migration and then never used —
 * defined, empty, referenced by nothing. This is what makes it a table rather
 * than a diagram.
 */
final class StaffRepository extends Repository
{
    protected string $model = Staff::class;

    /** @return array<string,mixed>|null */
    public function forMember(int $organizationId, int $userId): ?array
    {
        return Database::selectOne(
            'SELECT * FROM staff WHERE organization_id = :org AND user_id = :uid',
            ['org' => $organizationId, 'uid' => $userId],
        );
    }

    /**
     * Write the employment record, creating it the first time.
     *
     * Upsert rather than create-then-update because there is exactly one such
     * record per person per clinic — the unique key says so — and the caller
     * should not have to know whether this is the first time.
     *
     * @param array<string,mixed> $data
     */
    public function put(int $organizationId, int $userId, array $data): array
    {
        Database::statement(
            'INSERT INTO staff
                (organization_id, user_id, employee_no, department, designation,
                 hired_at, created_at, updated_at)
             VALUES (:org, :uid, :emp, :dept, :desig, :hired, :now, :now)
             ON DUPLICATE KEY UPDATE
                employee_no = VALUES(employee_no),
                department  = VALUES(department),
                designation = VALUES(designation),
                hired_at    = VALUES(hired_at),
                updated_at  = VALUES(updated_at)',
            [
                'org'   => $organizationId,
                'uid'   => $userId,
                'emp'   => $data['employee_no'] ?? null,
                'dept'  => $data['department']  ?? null,
                'desig' => $data['designation'] ?? null,
                'hired' => $data['hired_at']    ?? null,
                'now'   => now(),
            ],
        );

        return $this->forMember($organizationId, $userId) ?? [];
    }

    /**
     * Is this employee number already taken by somebody else here?
     *
     * Not a database constraint, because plenty of clinics do not use employee
     * numbers at all and a UNIQUE over NULLs would be the wrong shape. It is a
     * rule the service applies when a number is actually given.
     */
    public function employeeNoTaken(int $organizationId, string $employeeNo, int $exceptUserId): bool
    {
        $row = Database::selectOne(
            'SELECT 1 AS found FROM staff
              WHERE organization_id = :org AND employee_no = :emp AND user_id <> :uid
              LIMIT 1',
            ['org' => $organizationId, 'emp' => $employeeNo, 'uid' => $exceptUserId],
        );

        return $row !== null;
    }

    /** Remove the employment record when somebody leaves the clinic. */
    public function remove(int $organizationId, int $userId): void
    {
        Database::statement(
            'DELETE FROM staff WHERE organization_id = :org AND user_id = :uid',
            ['org' => $organizationId, 'uid' => $userId],
        );
    }
}
