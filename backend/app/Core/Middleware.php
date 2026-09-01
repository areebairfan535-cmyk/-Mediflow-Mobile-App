<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Middleware contract. Implementations either mutate the Request (attaching
 * the user, the tenant, the permission set) or throw an HttpException to
 * stop the pipeline.
 *
 * Order matters and is fixed in routes/api.php:
 *   RateLimit -> Auth -> Tenant -> Permission
 * Tenant needs the user, and Permission needs the tenant's role.
 */
interface Middleware
{
    /**
     * @param array<int,string> $args Extra arguments from the route
     *                                definition, e.g. the permission slug.
     */
    public function handle(Request $request, array $args = []): void;
}
