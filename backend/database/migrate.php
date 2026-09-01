<?php
declare(strict_types=1);

/**
 * Migration runner.
 *
 *   php database/migrate.php            run pending migrations
 *   php database/migrate.php --status   list what has and has not run
 *   php database/migrate.php --fresh    DROP every table, then run all
 *
 * Files are applied in filename order. Each file may hold several statements
 * separated by ';'. Applied filenames are recorded in `migrations`, so
 * re-running is a no-op.
 */

if (PHP_SAPI !== 'cli') {
    exit("This script must be run from the command line.\n");
}

$config = require dirname(__DIR__) . '/bootstrap/app.php';

use App\Core\Database;

$argsList = $argv ?? [];
$fresh    = in_array('--fresh',  $argsList, true);
$status   = in_array('--status', $argsList, true);

$dir   = __DIR__ . '/migrations';
$files = glob($dir . '/*.sql') ?: [];
sort($files);

if ($files === []) {
    exit("No migration files found in $dir\n");
}

/** The bookkeeping table lives inside 006, so create it up front. */
Database::statement(
    'CREATE TABLE IF NOT EXISTS migrations (
        id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(191) NOT NULL,
        batch    INT UNSIGNED NOT NULL DEFAULT 1,
        ran_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_migration (filename)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$applied = array_column(
    Database::select('SELECT filename FROM migrations ORDER BY id'),
    'filename',
);

// ---------------- --status ----------------
if ($status) {
    echo "Migration status\n----------------\n";
    foreach ($files as $file) {
        $name = basename($file);
        printf("  [%s] %s\n", in_array($name, $applied, true) ? 'x' : ' ', $name);
    }
    $tables = Database::select('SHOW TABLES');
    echo "\nTables in database: " . count($tables) . "\n";
    exit(0);
}

// ---------------- --fresh ----------------
if ($fresh) {
    echo "!! --fresh: dropping all tables in `{$config['database']['database']}`\n";

    Database::statement('SET FOREIGN_KEY_CHECKS = 0');
    foreach (Database::select('SHOW TABLES') as $row) {
        $table = (string) array_values($row)[0];
        Database::statement('DROP TABLE IF EXISTS `' . $table . '`');
        echo "   dropped $table\n";
    }
    Database::statement('SET FOREIGN_KEY_CHECKS = 1');

    Database::statement(
        'CREATE TABLE IF NOT EXISTS migrations (
            id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            filename VARCHAR(191) NOT NULL,
            batch    INT UNSIGNED NOT NULL DEFAULT 1,
            ran_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_migration (filename)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $applied = [];
}

// ---------------- run ----------------
$batchRow = Database::selectOne('SELECT COALESCE(MAX(batch), 0) AS b FROM migrations');
$batch    = (int) ($batchRow['b'] ?? 0) + 1;

$ran = 0;

foreach ($files as $file) {
    $name = basename($file);

    if (in_array($name, $applied, true)) {
        echo "  skip  $name\n";
        continue;
    }

    $sql = file_get_contents($file);
    if ($sql === false) {
        exit("Could not read $file\n");
    }

    echo "  run   $name ... ";

    try {
        foreach (splitStatements($sql) as $statement) {
            Database::statement($statement);
        }
        Database::statement(
            'INSERT INTO migrations (filename, batch) VALUES (:f, :b)',
            ['f' => $name, 'b' => $batch],
        );
        echo "ok\n";
        $ran++;
    } catch (\Throwable $e) {
        echo "FAILED\n\n";
        echo "  {$e->getMessage()}\n\n";
        exit(1);
    }
}

$tableCount = count(Database::select('SHOW TABLES'));

echo "\n";
echo $ran === 0
    ? "Nothing to migrate — schema is up to date.\n"
    : "Applied $ran migration file(s) as batch $batch.\n";
echo "Tables now in `{$config['database']['database']}`: $tableCount\n";

/**
 * Split a migration file into statements.
 *
 * Comments are stripped first so a ';' inside a `--` comment cannot split a
 * statement in the wrong place. Quoted string literals are respected, which
 * matters because ENUM definitions contain both commas and quotes.
 *
 * @return list<string>
 */
function splitStatements(string $sql): array
{
    $statements = [];
    $current    = '';
    $inString   = false;
    $quote      = '';
    $length     = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $sql[$i + 1] ?? '';

        if (!$inString) {
            // Line comment: -- ... or # ...
            if (($char === '-' && $next === '-') || $char === '#') {
                while ($i < $length && $sql[$i] !== "\n") {
                    $i++;
                }
                $current .= "\n";
                continue;
            }
            // Block comment
            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i);
                $i   = $end === false ? $length : $end + 1;
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $inString = true;
                $quote    = $char;
            }
        } else {
            if ($char === '\\') {
                // Escaped character inside a literal: consume both.
                $current .= $char . $next;
                $i++;
                continue;
            }
            if ($char === $quote) {
                $inString = false;
            }
        }

        if ($char === ';' && !$inString) {
            $trimmed = trim($current);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $current = '';
            continue;
        }

        $current .= $char;
    }

    $trimmed = trim($current);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}
