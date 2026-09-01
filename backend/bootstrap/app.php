<?php
declare(strict_types=1);

/**
 * Bootstrap: autoloader, .env, config, database, timezone.
 *
 * Returns the config array. Used by public/index.php and by every CLI script
 * (migrate, seed) so both share exactly one startup path.
 */

$root = dirname(__DIR__);

// ---- PSR-4-ish autoloader for App\ -> app/ (no Composer, per §24) ----
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path     = $root . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

require_once $root . '/app/Support/helpers.php';

// Exceptions live together in one file, so the autoloader cannot find them
// by class name. Load them eagerly.
require_once $root . '/app/Core/Exceptions.php';

load_env($root . '/.env');

/** @var array<string,mixed> $config */
$config = require $root . '/config/app.php';

// Middleware and services read config through this global rather than
// threading it through every constructor.
$GLOBALS['__config'] = $config;

date_default_timezone_set($config['timezone']);

// Debug display is opt-in; production must never leak stack traces to a client.
if ($config['debug']) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
}
ini_set('log_errors', '1');
ini_set('error_log', $root . '/storage/logs/php-error.log');

\App\Core\Database::init($config['database']);

return $config;
