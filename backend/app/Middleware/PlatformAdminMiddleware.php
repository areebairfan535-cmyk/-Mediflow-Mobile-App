<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\ForbiddenException;
use App\Core\Middleware;
use App\Core\Request;

/**
 * Gate for the super-admin panel (§21). Platform admins are the only callers
 * allowed on cross-tenant routes, so those routes carry this instead of
 * 'tenant' and are the only place withoutTenantScope() is reachable via HTTP.
 */
final class PlatformAdminMiddleware implements Middleware
{
    public function handle(Request $request, array $args = []): void
    {
        if (!$request->isPlatformAdmin()) {
            throw new ForbiddenException('Platform administrator access required');
        }
    }
}
