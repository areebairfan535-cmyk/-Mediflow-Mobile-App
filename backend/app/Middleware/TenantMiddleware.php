<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\ForbiddenException;
use App\Core\Middleware;
use App\Core\Request;
use App\Repositories\OrganizationRepository;
use App\Services\RbacService;

/**
 * Resolves and VERIFIES the active tenant (§10).
 *
 * The organization id is chosen in this order:
 *   1. X-Organization-Id header (client switching between clinics)
 *   2. the organization the session's token was issued for
 *   3. the user's single membership, if they belong to exactly one
 *
 * Whichever id arrives, membership is then re-checked against
 * organization_users. A client-supplied header is a *request*, never a
 * grant: an id the user is not an active member of is rejected with 403.
 *
 * On success the Request carries the organization, the membership (with its
 * role) and the resolved permission set. Repositories are bound to that id,
 * so a leak would require deliberately calling withoutTenantScope().
 */
final class TenantMiddleware implements Middleware
{
    public function handle(Request $request, array $args = []): void
    {
        $user = $request->user();
        if ($user === null) {
            throw new ForbiddenException('Tenant resolution requires authentication');
        }

        $orgRepo = new OrganizationRepository();
        $rbac    = new RbacService();
        $userId  = (int) $user['id'];

        $requested = $this->requestedOrganizationId($request);

        // A platform admin may inspect any organization, but must name one.
        if ($requested === null) {
            $memberships = $rbac->membershipsFor($userId);

            if (count($memberships) === 1) {
                $requested = (int) $memberships[0]['organization_id'];
            } elseif ($memberships === []) {
                throw new ForbiddenException(
                    'Your account does not belong to any organization yet.'
                );
            } else {
                throw new ForbiddenException(
                    'You belong to several organizations — send the X-Organization-Id header '
                    . 'to choose one.'
                );
            }
        }

        $organization = $orgRepo->withoutTenantScope()->find($requested);
        if ($organization === null) {
            // Do not confirm the existence of other tenants' organizations.
            throw new ForbiddenException('You do not have access to this organization');
        }
        if ($organization['status'] !== 'active') {
            throw new ForbiddenException(
                'This organization is ' . $organization['status'] . '.'
            );
        }

        $membership = $rbac->membership($userId, $requested);

        if ($membership === null || $membership['status'] !== 'active') {
            // Platform admins get read access without a membership row (§21).
            if (!$request->isPlatformAdmin()) {
                throw new ForbiddenException('You do not have access to this organization');
            }
            $membership  = [
                'organization_id' => $requested,
                'user_id'         => $userId,
                'role_slug'       => 'platform_admin',
                'role_id'         => null,
                'status'          => 'active',
            ];
            $permissions = [];   // Request::can() short-circuits for platform admins
        } else {
            $permissions = $rbac->permissionsForRole((int) $membership['role_id']);
        }

        $request->setTenant($organization, $membership, $permissions);
    }

    private function requestedOrganizationId(Request $request): ?int
    {
        $header = $request->header('x-organization-id');
        if ($header !== null && ctype_digit(trim($header))) {
            return (int) trim($header);
        }
        $session = $request->query['__session_org_id'] ?? null;
        if ($session !== null && ctype_digit((string) $session)) {
            return (int) $session;
        }
        return null;
    }
}
