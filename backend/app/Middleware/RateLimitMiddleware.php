<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Database;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\TooManyRequestsException;

/**
 * Fixed-window rate limiter (§17).
 *
 * The bucket key is (identity, route) where identity is the user id when
 * authenticated and the client IP otherwise. Auth routes get a much tighter
 * limit because they are the brute-force target.
 *
 * Usage: 'throttle' for the default limit, 'throttle:auth' for the tight one.
 *
 * Storage is a MySQL table so it works on a stock XAMPP install with no Redis.
 * The single-statement upsert makes the increment atomic under concurrency.
 */
final class RateLimitMiddleware implements Middleware
{
    public function handle(Request $request, array $args = []): void
    {
        $config = $GLOBALS['__config']['rate_limit'] ?? [];
        if (($config['enabled'] ?? true) !== true) {
            return;
        }

        $isAuthBucket = ($args[0] ?? '') === 'auth';
        $max          = (int) ($isAuthBucket
            ? ($config['auth_max_requests'] ?? 10)
            : ($config['max_requests'] ?? 120));
        $window = (int) ($config['window_seconds'] ?? 60);

        $identity = $request->userId() !== null
            ? 'u:' . $request->userId()
            : 'ip:' . $request->ip();
        $key = hash('sha1', $identity . '|' . ($isAuthBucket ? 'auth' : $request->path));

        $windowStart = gmdate('Y-m-d H:i:s', (intdiv(time(), $window) * $window));

        // Atomic upsert: insert the bucket, or bump it if the window matches.
        // A stale window resets hits to 1 rather than accumulating.
        Database::statement(
            'INSERT INTO rate_limits (bucket_key, hits, window_start)
             VALUES (:key, 1, :start)
             ON DUPLICATE KEY UPDATE
                 hits         = IF(window_start = VALUES(window_start), hits + 1, 1),
                 window_start = VALUES(window_start)',
            ['key' => $key, 'start' => $windowStart],
        );

        $row  = Database::selectOne(
            'SELECT hits FROM rate_limits WHERE bucket_key = :key',
            ['key' => $key],
        );
        $hits = (int) ($row['hits'] ?? 1);

        if ($hits > $max) {
            $retryAfter = $window - (time() % $window);
            if (!headers_sent()) {
                header('Retry-After: ' . $retryAfter);
                header('X-RateLimit-Limit: ' . $max);
                header('X-RateLimit-Remaining: 0');
            }
            throw new TooManyRequestsException(
                'Too many requests. Try again in ' . $retryAfter . ' seconds.',
                $retryAfter,
            );
        }

        if (!headers_sent()) {
            header('X-RateLimit-Limit: ' . $max);
            header('X-RateLimit-Remaining: ' . max(0, $max - $hits));
        }
    }
}
