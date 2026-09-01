<?php
declare(strict_types=1);

/**
 * Application configuration. Values come from .env so nothing secret is
 * ever committed. env() is defined in app/Support/helpers.php.
 */

return [
    'name'      => env('APP_NAME', 'MediFlow'),
    'env'       => env('APP_ENV', 'local'),
    'debug'     => env('APP_DEBUG', 'true') === 'true',
    'url'       => rtrim(env('APP_URL', 'http://localhost:8000'), '/'),
    'timezone'  => env('APP_TIMEZONE', 'UTC'),

    'database' => [
        'host'     => env('DB_HOST', '127.0.0.1'),
        'port'     => (int) env('DB_PORT', '3306'),
        'database' => env('DB_DATABASE', 'mediflow'),
        'username' => env('DB_USERNAME', 'root'),
        'password' => env('DB_PASSWORD', ''),
        'charset'  => 'utf8mb4',
    ],

    'auth' => [
        // §11: short-lived access token, long-lived refresh token.
        'access_ttl_minutes'  => (int) env('AUTH_ACCESS_TTL_MINUTES', '30'),
        'refresh_ttl_days'    => (int) env('AUTH_REFRESH_TTL_DAYS', '30'),
        'max_failed_logins'   => (int) env('AUTH_MAX_FAILED_LOGINS', '5'),
        'lockout_minutes'     => (int) env('AUTH_LOCKOUT_MINUTES', '15'),
        'password_min_length' => 8,
    ],

    // §17: rate limiting. Defaults are per-window-per-identity.
    'rate_limit' => [
        'enabled'         => env('RATE_LIMIT_ENABLED', 'true') === 'true',
        'window_seconds'  => (int) env('RATE_LIMIT_WINDOW', '60'),
        'max_requests'    => (int) env('RATE_LIMIT_MAX', '120'),
        // Login/register are brute-force targets, so they get a tighter bucket.
        'auth_max_requests' => (int) env('RATE_LIMIT_AUTH_MAX', '10'),
    ],

    'storage' => [
        // §19: binaries on disk/object storage, metadata in the DB.
        'root'       => dirname(__DIR__) . '/storage/app/public',
        'public_url' => rtrim(env('APP_URL', 'http://localhost:8000'), '/') . '/storage',
        'max_upload_mb' => (int) env('MAX_UPLOAD_MB', '25'),
    ],

    'cors' => [
        'allowed_origins' => explode(',', env('CORS_ORIGINS', '*')),
    ],
];
