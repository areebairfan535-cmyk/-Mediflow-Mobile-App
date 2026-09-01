<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\ForbiddenException;
use App\Core\Middleware;
use App\Core\Request;

/**
 * RBAC gate (§11). Declared on the route:
 *
 *   $r->post('/invoices', [InvoiceController::class,'store'], ['perm:invoice.create']);
 *   $r->get('/reports',   [ReportController::class,'index'],  ['perm:report.view,billing.view']);
 *
 * Several slugs means "any of these" (OR). Requiring several at once is
 * intentionally not expressible here — that is a policy decision and belongs
 * in the service, next to the rule it protects.
 */
final class PermissionMiddleware implements Middleware
{
    public function handle(Request $request, array $args = []): void
    {
        if ($args === []) {
            throw new \LogicException('perm middleware used without a permission slug');
        }

        foreach ($args as $permission) {
            if ($request->can(trim($permission))) {
                return;
            }
        }

        throw new ForbiddenException(
            'Your role (' . ($request->roleSlug() ?? 'none') . ') cannot perform this action.'
        );
    }
}
