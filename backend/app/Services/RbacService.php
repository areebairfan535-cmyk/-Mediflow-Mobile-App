<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ConflictException;
use App\Core\ForbiddenException;
use App\Core\NotFoundException;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;

/**
 * Role / permission / membership rules (§11, §10).
 *
 * The permission set for a request is resolved once by TenantMiddleware and
 * then cached on the Request, so a request never re-queries RBAC per check.
 */
final class RbacService
{
    /** In-request memo: role_id => permission slugs. */
    private static array $permissionCache = [];

    public function __construct(
        private readonly RoleRepository $roles = new RoleRepository(),
        private readonly UserRepository $users = new UserRepository(),
    ) {
    }

    /** @return array<string,mixed>|null */
    public function membership(int $userId, int $organizationId): ?array
    {
        return $this->roles->membership($userId, $organizationId);
    }

    /** @return list<array<string,mixed>> */
    public function membershipsFor(int $userId): array
    {
        return $this->roles->membershipsForUser($userId);
    }

    /**
     * Where this person is waiting to be let in, or was refused.
     *
     * @return list<array<string,mixed>>
     */
    public function applicationsFor(int $userId): array
    {
        return $this->roles->applicationsForUser($userId);
    }

    /**
     * A doctor (or anyone) asks to join a clinic. The membership exists from
     * this moment — so the login works and /me can say "waiting" — but it is
     * `pending`, which TenantMiddleware treats exactly like no membership at
     * all. An owner's approval is what opens the door.
     *
     * @return array<string,mixed> the pending membership
     */
    public function apply(int $organizationId, int $userId, int $roleId, ?string $jobTitle): array
    {
        if ($this->membership($userId, $organizationId) !== null) {
            throw new ConflictException('This user is already a member of the organization');
        }

        $this->memberships()->add($organizationId, $userId, $roleId, $jobTitle, 'pending');

        return $this->membership($userId, $organizationId) ?? [];
    }

    /** @return list<string> */
    public function permissionsForRole(int $roleId): array
    {
        return self::$permissionCache[$roleId]
            ??= $this->roles->permissionSlugs($roleId);
    }

    /** @return list<array<string,mixed>> */
    public function assignableRoles(int $organizationId): array
    {
        return $this->roles->assignableIn($organizationId);
    }

    /** @return list<array<string,mixed>> */
    public function members(int $organizationId): array
    {
        return $this->roles->members($organizationId);
    }

    /**
     * The user ids that run this clinic — active owners, and the solo
     * practitioner where the practice is one person. Who gets told when a
     * patient submits something that needs a decision.
     *
     * @return list<int>
     */
    public function ownersOf(int $organizationId): array
    {
        $ids = [];
        foreach ($this->roles->members($organizationId) as $m) {
            if (($m['status'] ?? '') === 'active'
                && in_array($m['role_slug'] ?? '', ['org_owner', 'solo_practitioner'], true)
            ) {
                $ids[] = (int) $m['user_id'];
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Add an existing user to an organization with a role.
     *
     * Two guards matter here:
     *  - the role must belong to this organization or be a system template,
     *    so a caller cannot borrow another tenant's role row;
     *  - a user already in the organization is a conflict, not a silent update.
     *
     * @return array<string,mixed> the created membership
     */
    public function addMember(
        int $organizationId,
        int $userId,
        int $roleId,
        ?string $jobTitle = null,
    ): array {
        if ($this->users->find($userId) === null) {
            throw new NotFoundException('User not found');
        }
        if (!$this->roles->isUsableIn($roleId, $organizationId)) {
            throw new ForbiddenException('That role cannot be assigned in this organization');
        }
        if ($this->membership($userId, $organizationId) !== null) {
            throw new ConflictException('This user is already a member of the organization');
        }

        $this->memberships()->add($organizationId, $userId, $roleId, $jobTitle);

        return $this->membership($userId, $organizationId) ?? [];
    }

    /** Change a member's role, with the same cross-tenant guard as addMember. */
    public function changeRole(int $organizationId, int $userId, int $roleId): array
    {
        $membership = $this->membership($userId, $organizationId);
        if ($membership === null) {
            throw new NotFoundException('This user is not a member of the organization');
        }
        if (!$this->roles->isUsableIn($roleId, $organizationId)) {
            throw new ForbiddenException('That role cannot be assigned in this organization');
        }

        // An organization must never be left without an owner.
        if ($membership['role_slug'] === 'org_owner'
            && $this->countByRoleSlug($organizationId, 'org_owner') <= 1
        ) {
            throw new ConflictException(
                'This is the only owner of the organization — promote another owner first.'
            );
        }

        $this->memberships()->setRole($organizationId, $userId, $roleId);

        unset(self::$permissionCache[$roleId]);

        return $this->membership($userId, $organizationId) ?? [];
    }

    public function setMemberStatus(int $organizationId, int $userId, string $status): array
    {
        $membership = $this->membership($userId, $organizationId);
        if ($membership === null) {
            throw new NotFoundException('This user is not a member of the organization');
        }
        if ($status === 'disabled'
            && $membership['role_slug'] === 'org_owner'
            && $this->countByRoleSlug($organizationId, 'org_owner') <= 1
        ) {
            throw new ConflictException('Cannot disable the only owner of the organization');
        }

        $this->memberships()->setStatus($organizationId, $userId, $status);

        return $this->membership($userId, $organizationId) ?? [];
    }

    public function removeMember(int $organizationId, int $userId): void
    {
        $membership = $this->membership($userId, $organizationId);
        if ($membership === null) {
            throw new NotFoundException('This user is not a member of the organization');
        }
        if ($membership['role_slug'] === 'org_owner'
            && $this->countByRoleSlug($organizationId, 'org_owner') <= 1
        ) {
            throw new ConflictException('Cannot remove the only owner of the organization');
        }

        $this->memberships()->remove($organizationId, $userId);

        // The employment record goes with the membership. Keeping it would
        // leave an employee number attached to somebody the clinic no longer
        // has, and the next person to get that number would collide with it.
        $this->staff()->remove($organizationId, $userId);
    }

    // ---------------- employment records (§20) ----------------

    /**
     * One member's employment details.
     *
     * Null when the clinic has not recorded any — which is normal, not an
     * error. Plenty of small practices never fill this in.
     *
     * @return array<string,mixed>|null
     */
    public function staffProfile(int $organizationId, int $userId): ?array
    {
        $this->requireMember($organizationId, $userId);

        return $this->staff()->forMember($organizationId, $userId);
    }

    /**
     * Record or update what the clinic knows about somebody's employment.
     *
     * @param array<string,mixed> $data employee_no, department, designation, hired_at
     * @return array<string,mixed>
     */
    public function setStaffProfile(int $organizationId, int $userId, array $data): array
    {
        $this->requireMember($organizationId, $userId);

        $employeeNo = trim((string) ($data['employee_no'] ?? ''));
        if ($employeeNo !== '' && $this->staff()->employeeNoTaken($organizationId, $employeeNo, $userId)) {
            throw new ConflictException(
                "Employee number $employeeNo already belongs to somebody else here."
            );
        }

        return $this->staff()->put($organizationId, $userId, [
            'employee_no' => $employeeNo === '' ? null : $employeeNo,
            'department'  => $data['department']  ?? null,
            'designation' => $data['designation'] ?? null,
            'hired_at'    => $data['hired_at']    ?? null,
        ]);
    }

    /**
     * An employment record only means anything for somebody who works here.
     */
    private function requireMember(int $organizationId, int $userId): void
    {
        if ($this->membership($userId, $organizationId) === null) {
            throw new NotFoundException('This user is not a member of the organization');
        }
    }

    private function memberships(): \App\Repositories\MembershipRepository
    {
        return new \App\Repositories\MembershipRepository();
    }

    private function staff(): \App\Repositories\StaffRepository
    {
        return new \App\Repositories\StaffRepository();
    }

    private function countByRoleSlug(int $organizationId, string $roleSlug): int
    {
        return $this->memberships()->countByRoleSlug($organizationId, $roleSlug);
    }
}
