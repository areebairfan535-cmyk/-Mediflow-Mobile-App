<?php
declare(strict_types=1);

/**
 * Front controller. Every API request enters here.
 *
 * Responsibilities, and nothing more:
 *   - bootstrap the app
 *   - emit CORS / security headers
 *   - build the Request
 *   - register middleware aliases and routes
 *   - dispatch
 *   - convert any thrown exception into the standard error envelope
 */

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\PermissionMiddleware;
use App\Middleware\PlatformAdminMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Middleware\TenantMiddleware;

$config = require dirname(__DIR__) . '/bootstrap/app.php';

$request = Request::capture();

// Security headers (§17).
if (!headers_sent()) {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Request-Id: ' . $request->requestId);

    // The PHP version is nobody's business: it tells an attacker which
    // published vulnerabilities to try first.
    header_remove('X-Powered-By');

    // A JSON API loads nothing and frames nothing, so the strictest policy is
    // also the correct one. It costs nothing here and shuts the door on any
    // future response that accidentally returns HTML.
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

    // HSTS, but only over TLS. A browser ignores this header on a plain HTTP
    // response, and sending it anyway would assert something the deployment
    // has not earned — local development runs on http://127.0.0.1.
    //
    // Behind a reverse proxy the hop to PHP is plain HTTP, so X-Forwarded-Proto
    // is what says whether the *client* used TLS.
    $tlsDirect    = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
    $tlsForwarded = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

    if ($tlsDirect || $tlsForwarded) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

Response::cors($request, $config['cors']['allowed_origins']);

$router = new Router();

$router->registerAliases([
    'throttle' => RateLimitMiddleware::class,
    'auth'     => AuthMiddleware::class,
    'tenant'   => TenantMiddleware::class,
    'perm'     => PermissionMiddleware::class,
    'platform' => PlatformAdminMiddleware::class,
]);

require dirname(__DIR__) . '/routes/api.php';

try {
    $router->dispatch($request);
} catch (HttpException $e) {
    // Expected, typed failures: 4xx with a useful message.
    Response::error($e->getMessage(), $e->status, $e->errorCode, $e->fields);
} catch (\Throwable $e) {
    // Unexpected: log everything, tell the client nothing.
    error_log(sprintf(
        "[%s] %s: %s in %s:%d\n%s",
        $request->requestId,
        $e::class,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString(),
    ));

    if ($config['debug']) {
        Response::json([
            'error' => [
                'message'    => $e->getMessage(),
                'code'       => 'server_error',
                'type'       => $e::class,
                'file'       => $e->getFile() . ':' . $e->getLine(),
                'request_id' => $request->requestId,
                'trace'      => explode("\n", $e->getTraceAsString()),
            ],
        ], 500);
    }

    Response::error(
        'An unexpected error occurred. Reference: ' . $request->requestId,
        500,
        'server_error',
    );
}
