<?php
declare(strict_types=1);

/**
 * Put a backup back (§17).
 *
 *   php database/restore.php                    — list what is available
 *   php database/restore.php 2026-09-09_064042  — dry run: say what would happen
 *   php database/restore.php <stamp> --apply    — actually do it
 *   php database/restore.php <stamp> --apply --database=mediflow_drill
 *   php database/restore.php <stamp> --apply --db-only
 *
 * A backup nobody has restored is a guess, not a backup. This is the other
 * half, and it is meant to be rehearsed: --database= sends it at a scratch
 * copy so you can practise before you ever need the real thing.
 *
 * That is a flag and not an environment variable on purpose. env() reads .env
 * before the process environment, so `DB_DATABASE=drill php restore.php` is
 * silently ignored — it would print the scratch name and overwrite production.
 *
 * Restoring REPLACES the database. It refuses to do that without --apply, and
 * says exactly what it is about to overwrite first, because the moment you
 * need this is the moment you are least able to double-check.
 */

$root   = dirname(__DIR__);
$config = require $root . '/bootstrap/app.php';

$args     = array_slice($argv, 1);
$apply    = in_array('--apply', $args, true);
$dbOnly   = in_array('--db-only', $args, true);
$stamp    = null;
$outRoot  = $root . '/storage/backups';

$intoDatabase = null;

foreach ($args as $arg) {
    if (str_starts_with($arg, '--out=')) {
        $outRoot = rtrim(substr($arg, 6), '/\\');
    } elseif (str_starts_with($arg, '--database=')) {
        // An explicit flag, NOT an environment variable: env() reads .env
        // before the process environment, so DB_DATABASE=x on the command
        // line is silently ignored and the restore would hit the live
        // database while claiming to hit a scratch one.
        $intoDatabase = substr($arg, 11);
    } elseif (!str_starts_with($arg, '--')) {
        $stamp = $arg;
    }
}

$available = array_map('basename', glob($outRoot . '/*', GLOB_ONLYDIR) ?: []);
sort($available);

if ($stamp === null) {
    if ($available === []) {
        echo "No backups in $outRoot — run: php database/backup.php\n";
        exit(1);
    }
    echo "Backups in $outRoot:\n";
    foreach ($available as $name) {
        $sql   = $outRoot . '/' . $name . '/database.sql';
        $size  = is_file($sql) ? round(filesize($sql) / 1048576, 1) . ' MB' : 'no dump!';
        $files = is_dir($outRoot . '/' . $name . '/files') ? ' + files' : '';
        printf("  %-22s %s%s\n", $name, $size, $files);
    }
    echo "\nRestore one with: php database/restore.php <name> --apply\n";
    exit(0);
}

$dir = $outRoot . '/' . $stamp;
$sql = $dir . '/database.sql';

if (!is_dir($dir)) {
    fwrite(STDERR, "No backup called '$stamp' in $outRoot\n");
    exit(1);
}
if (!is_file($sql)) {
    fwrite(STDERR, "$stamp has no database.sql — it is not a usable backup\n");
    exit(1);
}

$db     = $config['database'];
$target = $intoDatabase ?? ($db['database'] ?? 'mediflow');

echo "Restore\n";
echo "=======\n";
echo "  from:     $dir\n";
echo "  taken:    " . gmdate('c', (int) filemtime($sql)) . "\n";
echo "  dump:     " . round(filesize($sql) / 1048576, 1) . " MB\n";
echo "  INTO:     $target on " . ($db['host'] ?? '127.0.0.1') . "\n";

// Say what is about to be lost, in the units that matter to somebody deciding.
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s', $db['host'], $db['port'], $target),
        $db['username'],
        $db['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $rows = $pdo->query(
        'SELECT (SELECT COUNT(*) FROM patients) p, (SELECT COUNT(*) FROM invoices) i'
    )->fetch(PDO::FETCH_ASSOC);
    printf("  replacing: %s patients, %s invoices currently in that database\n", $rows['p'], $rows['i']);
} catch (\Throwable) {
    echo "  replacing: (target database is empty or unreachable)\n";
}

if (!$apply) {
    echo "\nDry run. Nothing was changed.\n";
    echo "Rehearse against a scratch database first:\n";
    echo "  php database/restore.php $stamp --apply --database=mediflow_drill\n";
    echo "Then, when you mean it:\n";
    echo "  php database/restore.php $stamp --apply\n";
    exit(0);
}

// ---------------------------------------------------------------
// Database
// ---------------------------------------------------------------

$mysql = getenv('MYSQL_PATH') ?: 'C:/xampp/mysql/bin/mysql.exe';
if (!is_file($mysql)) {
    $mysql = 'mysql';
}

$credentials = $dir . '/.my.cnf';
file_put_contents($credentials, sprintf(
    "[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n",
    $db['username'] ?? 'root',
    $db['password'] ?? '',
    $db['host'] ?? '127.0.0.1',
    $db['port'] ?? '3306',
));
@chmod($credentials, 0600);

// The dump carries DROP TABLE for everything it holds, so the target does not
// need emptying first — but it does need to exist.
$create = sprintf(
    '%s --defaults-extra-file=%s -e %s 2>&1',
    escapeshellarg($mysql),
    escapeshellarg($credentials),
    escapeshellarg("CREATE DATABASE IF NOT EXISTS `$target` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"),
);
exec($create, $out1, $status1);

$load = sprintf(
    '%s --defaults-extra-file=%s %s < %s 2>&1',
    escapeshellarg($mysql),
    escapeshellarg($credentials),
    escapeshellarg($target),
    escapeshellarg($sql),
);
exec($load, $out2, $status2);
unlink($credentials);

if ($status1 !== 0 || $status2 !== 0) {
    fwrite(STDERR, "  restore FAILED\n  " . implode("\n  ", array_slice(array_merge($out1, $out2), 0, 8)) . "\n");
    exit(1);
}

echo "  database restored\n";

// ---------------------------------------------------------------
// Files
// ---------------------------------------------------------------

if (!$dbOnly && is_dir($dir . '/files')) {
    $destination = $root . '/storage/app/public';
    $restored    = 0;

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir . '/files', FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($items as $item) {
        /** @var SplFileInfo $item */
        $relative = substr($item->getPathname(), strlen($dir . '/files') + 1);
        $target   = $destination . '/' . str_replace('\\', '/', $relative);

        if ($item->isDir()) {
            @mkdir($target, 0770, true);
            continue;
        }
        @mkdir(dirname($target), 0770, true);
        if (copy($item->getPathname(), $target)) {
            $restored++;
        }
    }

    echo "  $restored file(s) restored\n";
}

// ---------------------------------------------------------------
// Prove it landed, rather than assuming
// ---------------------------------------------------------------

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s', $db['host'], $db['port'], $target),
        $db['username'],
        $db['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $check = $pdo->query(
        'SELECT (SELECT COUNT(*) FROM patients) p,
                (SELECT COUNT(*) FROM invoices) i,
                (SELECT COUNT(*) FROM audit_logs) a'
    )->fetch(PDO::FETCH_ASSOC);

    printf("  now holds: %s patients, %s invoices, %s audit rows\n", $check['p'], $check['i'], $check['a']);
} catch (\Throwable $e) {
    fwrite(STDERR, "  restored, but the database could not be read back: " . $e->getMessage() . "\n");
    exit(1);
}

echo "Done.\n";
