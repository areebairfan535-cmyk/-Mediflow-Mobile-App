<?php
declare(strict_types=1);

/**
 * Notification worker (§20).
 *
 *   php database/notify.php             send everything that is due
 *   php database/notify.php --status    show which channels are configured
 *   php database/notify.php --watch     keep running, every 60s
 *   php database/notify.php --limit=25  send at most 25 this pass
 *
 * Run it from cron/Task Scheduler every minute in production:
 *
 *   * * * * * php /path/to/backend/database/notify.php >> /var/log/mediflow-notify.log
 *
 * In-app notifications are read straight from the table by the patient app, so
 * they need no delivery — the worker exists for email, SMS and (later) push,
 * and for reminders, which are queued in advance with a `scheduled_for`.
 */

if (PHP_SAPI !== 'cli') {
    exit("This script must be run from the command line.\n");
}

$config = require dirname(__DIR__) . '/bootstrap/app.php';

use App\Services\Notifications\Dispatcher;

$args   = $argv ?? [];
$status = in_array('--status', $args, true);
$watch  = in_array('--watch', $args, true);
$limit  = 100;

foreach ($args as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m) === 1) {
        $limit = (int) $m[1];
    }
}

$dispatcher = new Dispatcher();

// ---------------------------------------------------------------

if ($status) {
    echo "\nNotification channels\n---------------------\n";
    foreach ($dispatcher->status() as $channel => $configured) {
        printf("  %-8s %s\n", $channel, $configured ? 'configured' : 'not configured');
    }

    $pending = App\Core\Database::selectOne(
        'SELECT
            SUM(status = \'queued\') AS queued,
            SUM(status = \'queued\' AND (scheduled_for IS NULL OR scheduled_for <= UTC_TIMESTAMP())) AS due,
            SUM(status = \'failed\') AS failed
           FROM notifications',
    ) ?? [];

    printf(
        "\n  queued %d   due now %d   given up %d\n\n",
        (int) ($pending['queued'] ?? 0),
        (int) ($pending['due'] ?? 0),
        (int) ($pending['failed'] ?? 0),
    );
    exit(0);
}

// ---------------------------------------------------------------

do {
    $counts = $dispatcher->run($limit);

    // Only speak up when something happened: a cron line that prints "0 sent"
    // every minute buries the run that mattered.
    if (array_sum($counts) > 0) {
        printf(
            "[%s] sent %d, skipped %d, retrying %d, gave up %d\n",
            gmdate('Y-m-d H:i:s'),
            $counts['sent'],
            $counts['skipped'],
            $counts['failed'],
            $counts['gave_up'],
        );
    }

    if ($watch) {
        sleep(60);
    }
} while ($watch);
