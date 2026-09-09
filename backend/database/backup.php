<?php
declare(strict_types=1);

/**
 * Take a backup (§17).
 *
 *   php database/backup.php              — database + uploaded files
 *   php database/backup.php --db-only    — skip the files
 *   php database/backup.php --keep=30    — how many days to retain (default 14)
 *   php database/backup.php --out=PATH   — where to write (default backend/storage/backups)
 *
 * Two things have to survive, and losing either one loses the record:
 *
 *   - the database, which holds what happened
 *   - storage/app/public, which holds the scans and reports the rows point at
 *
 * A backup of one without the other is a chart with the X-rays torn out, so
 * both go into the same timestamped folder and are restored together.
 *
 * The dump is written with --single-transaction, so it is a consistent
 * snapshot taken without locking the clinic out while it runs.
 */

$root   = dirname(__DIR__);
$config = require $root . '/bootstrap/app.php';

$args    = array_slice($argv, 1);
$dbOnly  = in_array('--db-only', $args, true);
$keep    = 14;
$outRoot = $root . '/storage/backups';

foreach ($args as $arg) {
    if (str_starts_with($arg, '--keep=')) {
        $keep = max(1, (int) substr($arg, 7));
    }
    if (str_starts_with($arg, '--out=')) {
        $outRoot = rtrim(substr($arg, 6), '/\\');
    }
}

$db = $config['database'];

$stamp = gmdate('Y-m-d_His');
$dir   = $outRoot . '/' . $stamp;

if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
    fwrite(STDERR, "Cannot create $dir\n");
    exit(1);
}

echo "Backing up to $dir\n";

// ---------------------------------------------------------------
// 1. The database
// ---------------------------------------------------------------

$mysqldump = getenv('MYSQLDUMP_PATH') ?: 'C:/xampp/mysql/bin/mysqldump.exe';
if (!is_file($mysqldump)) {
    $mysqldump = 'mysqldump';   // on PATH, e.g. a Linux host
}

$sqlFile = $dir . '/database.sql';

// The password goes in on stdin-ish form rather than the command line, where
// it would be visible to anyone running `ps`.
$credentials = $dir . '/.my.cnf';
file_put_contents($credentials, sprintf(
    "[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n",
    $db['username'] ?? 'root',
    $db['password'] ?? '',
    $db['host'] ?? '127.0.0.1',
    $db['port'] ?? '3306',
));
@chmod($credentials, 0600);

$command = sprintf(
    '%s --defaults-extra-file=%s --single-transaction --quick --routines --events %s > %s 2>&1',
    escapeshellarg($mysqldump),
    escapeshellarg($credentials),
    escapeshellarg($db['database'] ?? 'mediflow'),
    escapeshellarg($sqlFile),
);

exec($command, $output, $status);
unlink($credentials);

if ($status !== 0 || !is_file($sqlFile) || filesize($sqlFile) < 1024) {
    fwrite(STDERR, "  mysqldump failed (exit $status)\n");
    fwrite(STDERR, '  ' . implode("\n  ", array_slice($output, 0, 5)) . "\n");
    // A backup that failed must not be left looking like one that worked.
    @unlink($sqlFile);
    exit(1);
}

printf("  database.sql        %s\n", human((int) filesize($sqlFile)));

// ---------------------------------------------------------------
// 2. The uploaded files
// ---------------------------------------------------------------

if (!$dbOnly) {
    $source = $root . '/storage/app/public';
    $copied = 0;
    $bytes  = 0;

    if (is_dir($source)) {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            $relative = substr($item->getPathname(), strlen($source) + 1);
            $target   = $dir . '/files/' . str_replace('\\', '/', $relative);

            if ($item->isDir()) {
                @mkdir($target, 0770, true);
                continue;
            }
            @mkdir(dirname($target), 0770, true);
            if (copy($item->getPathname(), $target)) {
                $copied++;
                $bytes += $item->getSize();
            }
        }
    }

    printf("  files/              %d file(s), %s\n", $copied, human($bytes));
}

// ---------------------------------------------------------------
// 3. A note saying what this is, so a restorer is not guessing
// ---------------------------------------------------------------

file_put_contents($dir . '/MANIFEST.txt', implode("\n", [
    'MediFlow backup',
    'taken_at_utc: ' . gmdate('c'),
    'database:     ' . ($db['database'] ?? 'mediflow'),
    'host:         ' . ($db['host'] ?? '127.0.0.1'),
    'contents:     database.sql' . ($dbOnly ? '' : ' + files/'),
    '',
    'To restore:   php database/restore.php ' . $stamp,
    '',
]) . "\n");

// ---------------------------------------------------------------
// 4. Retention — a disk that fills up is its own outage
// ---------------------------------------------------------------

$cutoff  = time() - ($keep * 86400);
$removed = 0;

foreach (glob($outRoot . '/*', GLOB_ONLYDIR) ?: [] as $old) {
    if (basename($old) === $stamp) {
        continue;
    }
    // Read the age from the folder name, not the filesystem: copying a backup
    // elsewhere and back would otherwise make it look new.
    $name = basename($old);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})_/', $name, $m)) {
        continue;
    }
    $taken = strtotime("$m[1]-$m[2]-$m[3]");
    if ($taken !== false && $taken < $cutoff) {
        rrmdir($old);
        $removed++;
    }
}

if ($removed > 0) {
    printf("  retention           removed %d backup(s) older than %d days\n", $removed, $keep);
}

echo "Done.\n";

function human(int $bytes): string
{
    foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
        if ($bytes < 1024) {
            return round($bytes, 1) . " $unit";
        }
        $bytes = (int) ($bytes / 1024);
    }
    return $bytes . ' TB';
}

function rrmdir(string $path): void
{
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $full = $path . '/' . $entry;
        is_dir($full) ? rrmdir($full) : @unlink($full);
    }
    @rmdir($path);
}
