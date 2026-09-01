<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\ConflictException;
use App\Core\Controller;
use App\Core\Database;
use App\Core\NotFoundException;
use App\Core\Request;
use App\Repositories\OrganizationRepository;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use App\Services\AuditService;
use App\Services\RbacService;

/**
 * Organization (tenant) management and clinic onboarding (§22).
 *
 * POST   /api/v1/organizations            — onboard a clinic; caller becomes owner
 * GET    /api/v1/organizations/current    — active tenant's settings
 * PUT    /api/v1/organizations/current    — update settings
 * GET    /api/v1/organizations/current/members
 * POST   /api/v1/organizations/current/members
 * PUT    /api/v1/organizations/current/members/{userId}/role
 * PUT    /api/v1/organizations/current/members/{userId}/status
 * DELETE /api/v1/organizations/current/members/{userId}
 * GET    /api/v1/organizations/current/roles
 */
final class OrganizationController extends Controller
{
    /**
     * Onboarding: create the organization and make the caller its owner,
     * atomically. Half-created tenants are worse than none, so both writes
     * share one transaction.
     */
    public function store(Request $request): never
    {
        $data = $this->validate($request, [
            'name'         => 'required|string|min:2|max:255',
            'country_code' => 'required|string|size:2',
            'email'        => 'nullable|email|max:255',
            'phone'        => 'nullable|string|max:32',
            'address'      => 'nullable|string|max:500',
            'city'         => 'nullable|string|max:120',
            // §22: the plan is chosen at sign-up. Omitted means Free.
            'plan'         => 'nullable|string|max:40',
        ]);

        $country = Database::selectOne(
            'SELECT * FROM countries WHERE code = :code AND is_active = 1',
            ['code' => strtoupper((string) $data['country_code'])],
        );
        if ($country === null) {
            throw new NotFoundException(
                'Country ' . $data['country_code'] . ' is not configured on this platform'
            );
        }

        $organizations = new OrganizationRepository();
        $slug          = $this->uniqueSlug($organizations, (string) $data['name']);
        $userId        = (int) $request->userId();

        $organization = Database::transaction(
            function () use ($organizations, $data, $slug, $country, $userId): array {
                $org = $organizations->create([
                    'name'       => trim((string) $data['name']),
                    'slug'       => $slug,
                    'country_id' => (int) $country['id'],
                    'email'      => $data['email']   ?? null,
                    'phone'      => $data['phone']   ?? null,
                    'address'    => $data['address'] ?? null,
                    'city'       => $data['city']    ?? null,
                    'status'     => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $ownerRole = (new RoleRepository())->findSystemRole('org_owner');
                if ($ownerRole === null) {
                    throw new \RuntimeException(
                        'System role org_owner is missing — run database/seed.php'
                    );
                }

                (new RbacService())->addMember(
                    (int) $org['id'],
                    $userId,
                    (int) $ownerRole['id'],
                    'Owner',
                );

                // §22 onboarding: choose plan → organization created. Inside
                // the same transaction, so a clinic can never exist without a
                // subscription — a missing one would otherwise have to be read
                // as either "free" or "unlimited", and one of those is a leak.
                \App\Services\SubscriptionService::startFor(
                    (int) $org['id'],
                    (string) ($country['currency_code'] ?? 'USD'),
                    isset($data['plan']) ? (string) $data['plan'] : null,
                );

                return $org;
            },
        );

        (new AuditService())->log(
            $request,
            'create',
            'organization',
            (int) $organization['id'],
            null,
            ['name' => $organization['name'], 'slug' => $organization['slug']],
        );

        $this->created([
            'organization' => $organizations->settings((int) $organization['id']),
            'message'      => 'Organization created. Send X-Organization-Id: '
                              . $organization['id'] . ' to work inside it.',
        ]);
    }

    public function current(Request $request): never
    {
        $this->ok([
            'organization' => (new OrganizationRepository())
                ->settings((int) $request->organizationId()),
        ]);
    }

    public function updateCurrent(Request $request): never
    {
        $data = $this->validate($request, [
            'name'           => 'nullable|string|min:2|max:255',
            'email'          => 'nullable|email|max:255',
            'phone'          => 'nullable|string|max:32',
            'address'        => 'nullable|string|max:500',
            'city'           => 'nullable|string|max:120',
            'currency_code'  => 'nullable|string|size:3',
            'timezone'       => 'nullable|string|max:64',
            'tax_rate'       => 'nullable|numeric|between:0,1',
            'invoice_prefix' => 'nullable|string|max:16',
        ]);

        $orgId         = (int) $request->organizationId();
        $organizations = new OrganizationRepository();
        $before        = $organizations->find($orgId) ?? [];

        $updated = $organizations->update($orgId, $data);

        (new AuditService())->logUpdate($request, 'organization', $orgId, $before, $updated);

        $this->ok(['organization' => $organizations->settings($orgId)]);
    }

    public function members(Request $request): never
    {
        $this->ok([
            'members' => (new RbacService())->members((int) $request->organizationId()),
        ]);
    }

    /**
     * Add a member. If no account exists for the email, one is created and a
     * temporary password is returned so the owner can hand it over — a real
     * deployment would email an invite link instead (§20).
     */
    public function addMember(Request $request): never
    {
        $data = $this->validate($request, [
            'email'     => 'required|email|max:255',
            'role_id'   => 'required|integer',
            'name'      => 'nullable|string|min:2|max:255',
            'job_title' => 'nullable|string|max:120',
        ]);

        // §22: staff accounts are a plan limit. Checked before an invite is
        // sent, so nobody is told they have joined a clinic that cannot hold them.
        \App\Services\SubscriptionService::for($request)->assertWithin('staff');

        $users            = new UserRepository();
        $orgId            = (int) $request->organizationId();
        $temporaryPassword = null;

        $user = $users->firstWhere(['email' => strtolower((string) $data['email'])]);

        if ($user === null) {
            if (!isset($data['name'])) {
                throw new \App\Core\ValidationException(
                    ['name' => ['Name is required when inviting a new person.']]
                );
            }
            $temporaryPassword = strtoupper(substr(str_random(6), 0, 10));
            $user = $users->create([
                'name'       => (string) $data['name'],
                'email'      => strtolower((string) $data['email']),
                'password'   => UserRepository::hashPassword($temporaryPassword),
                'status'     => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $membership = (new RbacService())->addMember(
            $orgId,
            (int) $user['id'],
            (int) $data['role_id'],
            $data['job_title'] ?? null,
        );

        (new AuditService())->log(
            $request,
            'create',
            'organization_member',
            (int) $user['id'],
            null,
            ['email' => $user['email'], 'role_id' => $data['role_id']],
        );

        $payload = ['member' => $membership, 'user' => $user];
        if ($temporaryPassword !== null) {
            $payload['temporary_password'] = $temporaryPassword;
            $payload['message'] = 'Account created. Share this password securely; '
                                . 'the user must change it on first sign-in.';
        }

        $this->created($payload);
    }

    public function changeMemberRole(Request $request): never
    {
        $data = $this->validate($request, ['role_id' => 'required|integer']);

        $membership = (new RbacService())->changeRole(
            (int) $request->organizationId(),
            $request->intParam('userId'),
            (int) $data['role_id'],
        );

        (new AuditService())->log(
            $request,
            'update',
            'organization_member',
            $request->intParam('userId'),
            null,
            ['role_id' => $data['role_id']],
        );

        $this->ok(['member' => $membership]);
    }

    public function changeMemberStatus(Request $request): never
    {
        $data = $this->validate($request, [
            'status' => 'required|in:active,disabled',
        ]);

        $membership = (new RbacService())->setMemberStatus(
            (int) $request->organizationId(),
            $request->intParam('userId'),
            (string) $data['status'],
        );

        (new AuditService())->log(
            $request,
            'update',
            'organization_member',
            $request->intParam('userId'),
            null,
            ['status' => $data['status']],
        );

        $this->ok(['member' => $membership]);
    }

    public function removeMember(Request $request): never
    {
        $userId = $request->intParam('userId');

        (new RbacService())->removeMember((int) $request->organizationId(), $userId);

        (new AuditService())->log($request, 'delete', 'organization_member', $userId);

        $this->ok(['message' => 'Member removed']);
    }

    public function roles(Request $request): never
    {
        $rbac  = new RbacService();
        $roles = $rbac->assignableRoles((int) $request->organizationId());

        // Include each role's permissions so an admin UI can show what a role grants.
        $roles = array_map(
            static function (array $role) use ($rbac): array {
                $role['permissions'] = $rbac->permissionsForRole((int) $role['id']);
                return $role;
            },
            $roles,
        );

        $this->ok(['roles' => $roles]);
    }

    private function uniqueSlug(OrganizationRepository $repository, string $name): string
    {
        $base = preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? 'clinic';
        $base = trim($base, '-') ?: 'clinic';
        $base = substr($base, 0, 100);

        $slug = $base;
        for ($i = 2; $repository->slugExists($slug); $i++) {
            $slug = $base . '-' . $i;
            if ($i > 200) {
                throw new ConflictException('Could not generate a unique slug for this name');
            }
        }
        return $slug;
    }
}
