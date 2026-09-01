<?php
declare(strict_types=1);

/**
 * Global helpers. Loaded first by bootstrap/app.php, before config.
 */

if (!function_exists('env')) {
    /**
     * Read a value from the parsed .env, falling back to $default.
     * Values are stored in $GLOBALS['__env'] by load_env().
     */
    function env(string $key, ?string $default = null): ?string
    {
        $vars = $GLOBALS['__env'] ?? [];
        if (array_key_exists($key, $vars) && $vars[$key] !== '') {
            return $vars[$key];
        }
        return $default;
    }
}

if (!function_exists('load_env')) {
    /**
     * Minimal .env parser — no Composer, per the plan's "Core PHP" constraint.
     * Supports KEY=value, # comments, and optional single/double quotes.
     */
    function load_env(string $path): void
    {
        $vars = [];
        if (is_file($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                $eq = strpos($line, '=');
                if ($eq === false) {
                    continue;
                }
                $key   = trim(substr($line, 0, $eq));
                $value = trim(substr($line, $eq + 1));
                // Strip surrounding quotes if present.
                if (strlen($value) >= 2
                    && (($value[0] === '"' && str_ends_with($value, '"'))
                     || ($value[0] === "'" && str_ends_with($value, "'")))
                ) {
                    $value = substr($value, 1, -1);
                }
                $vars[$key] = $value;
            }
        }
        $GLOBALS['__env'] = $vars;
    }
}

if (!function_exists('now')) {
    /** Current UTC timestamp in MySQL DATETIME format. */
    function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}

if (!function_exists('str_random')) {
    function str_random(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }
}

if (!function_exists('array_only')) {
    /**
     * Keep only $keys that are actually present in $data.
     * Used for partial updates: absent key = "don't touch", not "set null".
     *
     * @param array<string,mixed> $data
     * @param list<string>        $keys
     * @return array<string,mixed>
     */
    function array_only(array $data, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = $data[$key];
            }
        }
        return $out;
    }
}

if (!function_exists('money')) {
    /** Round to 2dp as a string, so DECIMAL columns never receive float drift. */
    function money(int|float|string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
