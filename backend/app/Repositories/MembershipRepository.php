<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Repository;
use App\Models\Membership;

/**
 * Who belongs to which clinic, and as what (§16).
 *
 * Moved out of RbacService per §18. The service decides whether a change is
 * allowed — "you cannot remove the last owner" is a rule, and it stays there;
 * this writes the change down.
 */
final class MembershipRepository extends Repository
{
    protected string $model = Membership::class;

    public function add(int $organizationId, int $userId, int $roleId, ?string $jobTitle): void
    {
        Database::statement(
            'INSERT INTO organization_users
                (organization_id, user_id, role_id, job_title, status, joined_at, created_at, updated_at)
             VALUES (:org, :uid, :role, :title, \'active\', :now, :now, :now)',
            [
                'org'   => $organizationId,
                'uid'   => $userId,
                'role'  => $roleId,
                'title' => $jobTitle,
                'now'   => now(),
            ],
        );
    }

    public function setRole(int $organizationId, int $userId, int $roleId): void
    {
        Database::statement(
            'UPDATE organization_users
                SET role_id = :role, updated_at = :now
              WHERE organization_id = :org AND user_id = :uid',
            ['role' => $roleId, 'now' => now(), 'org' => $organizationId, 'uid' => $userId],
        );
    }

    public function setStatus(int $organizationId, int $userId, string $status): void
    {
        Database::statement(
            'UPDATE organization_users
                SET status = :status, updated_at = :now
              WHERE organization_id = :org AND user_id = :uid',
            ['status' => $status, 'now' => now(), 'org' => $organizationId, 'uid' => $userId],
        );
    }

    /**
     * Remove somebody from a clinic.
     *
     * The membership goes; the user account does not. Someone who leaves one
     * clinic may still work at another, and their login is theirs.
     */
    public function remove(int $organizationId, int $userId): void
    {
        Database::statement(
            'DELETE FROM organization_users WHERE organization_id = :org AND user_id = :uid',
            ['org' => $organizationId, 'uid' => $userId],
        );
    }

    /**
     * How many active people hold a given role here.
     *
     * The question behind "may this owner be removed?" — a clinic with no
     * owner is a clinic nobody can administer.
     */
    public function countByRoleSlug(int $organizationId, string $roleSlug): int
    {
        $row = Database::selectOne(
            'SELECT COUNT(*) AS c
               FROM organization_users ou
               JOIN roles r ON r.id = ou.role_id
              WHERE ou.organization_id = :org
                AND ou.status = \'active\'
                AND r.slug    = :slug',
            ['org' => $organizationId, 'slug' => $roleSlug],
        );

        return (int) ($row['c'] ?? 0);
    }
}
