<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Repositories\OrganizationRepository;
use App\Repositories\UserRepository;
use App\Services\AuditService;
use App\Services\RbacService;

/**
 * GET /api/v1/me         — identity + memberships + active tenant + permissions
 * PUT /api/v1/me         — update own profile
 *
 * /me is the first call every client makes after login: it tells the app which
 * screens to render, because it returns the permission list for the active
 * organization rather than a bare role name.
 */
final class MeController extends Controller
{
    public function show(Request $request): never
    {
        $userId = (int) $request->userId();
        $rbac   = new RbacService();

        $payload = [
            'user'          => $request->user(),
            'organizations' => $rbac->membershipsFor($userId),
        ];

        // Tenant context is present only on routes that ran TenantMiddleware.
        if ($request->organizationId() !== null) {
            $payload['active_organization'] =
                (new OrganizationRepository())->settings($request->organizationId());
            $payload['role']        = $request->roleSlug();
            $payload['permissions'] = $request->permissions();
        }

        $this->ok($payload);
    }

    public function update(Request $request): never
    {
        $data = $this->validate($request, [
            'name'   => 'nullable|string|min:2|max:255',
            'phone'  => 'nullable|string|max:32',
            'locale' => 'nullable|string|max:10',
        ]);

        if ($data === []) {
            $this->ok(['user' => $request->user()]);
        }

        $users  = new UserRepository();
        $userId = (int) $request->userId();
        $before = $users->find($userId) ?? [];

        $updated = $users->update($userId, $data);

        (new AuditService())->logUpdate($request, 'user', $userId, $before, $updated);

        $this->ok(['user' => $updated]);
    }
}
