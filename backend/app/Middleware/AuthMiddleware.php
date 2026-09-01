<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Middleware;
use App\Core\Request;
use App\Core\UnauthorizedException;
use App\Services\TokenService;

/**
 * Resolves the Bearer access token into a user and attaches it to the Request.
 *
 * Only ACCESS tokens are accepted here — presenting a refresh token to a
 * normal endpoint is rejected, because a refresh token is long-lived and
 * must only ever be spendable at POST /auth/refresh.
 */
final class AuthMiddleware implements Middleware
{
    public function handle(Request $request, array $args = []): void
    {
        $plaintext = $request->bearerToken();
        if ($plaintext === null) {
            throw new UnauthorizedException('Missing Bearer token');
        }

        $resolved = (new TokenService())->resolveAccessToken($plaintext);
        if ($resolved === null) {
            throw new UnauthorizedException('Invalid or expired token');
        }

        [$user, $token] = $resolved;

        if (($user['status'] ?? 'active') !== 'active') {
            throw new UnauthorizedException('This account is disabled');
        }

        $request->setUser($user, (int) $token['id']);

        // The session remembers which tenant it was issued for; TenantMiddleware
        // reads this when the client does not send X-Organization-Id.
        if ($token['active_org_id'] !== null) {
            $request->query['__session_org_id'] = (string) $token['active_org_id'];
        }
    }
}
