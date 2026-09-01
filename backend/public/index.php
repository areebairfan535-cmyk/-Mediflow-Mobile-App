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

// Security headers (§17). HSTS is only meaningful over HTTPS, so it is left
// to the web server / reverse proxy that terminates TLS.
if (!headers_sent()) {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Request-Id: ' . $request->requestId);
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
