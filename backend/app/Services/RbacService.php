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
    }

    private function memberships(): \App\Repositories\MembershipRepository
    {
        return new \App\Repositories\MembershipRepository();
    }

    private function countByRoleSlug(int $organizationId, string $roleSlug): int
    {
        return $this->memberships()->countByRoleSlug($organizationId, $roleSlug);
    }
}
