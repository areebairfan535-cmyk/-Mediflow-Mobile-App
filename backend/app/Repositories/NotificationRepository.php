<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Repository;
use App\Models\Notification;

/**
 * The notification queue and the inbox that reads from it (§20).
 *
 * Moved out of NotificationService and Notifications\Dispatcher per §18. The
 * service decides what a message says and which channels it goes to; the
 * dispatcher decides when to give up trying. Both of those are rules. Putting
 * a row in a table is not.
 *
 * Not tenant scoped, because organization_id is NULL for account-level
 * messages like a password-reset code.
 */
final class NotificationRepository extends Repository
{
    protected string $model = Notification::class;

    /** How far back the in-app inbox reaches. */
    public const INBOX_DAYS = 90;

    /**
     * Queue one message.
     *
     * @param array<string,mixed> $data
     */
    public function queue(array $data): void
    {
        Database::statement(
            'INSERT INTO notifications
                (organization_id, user_id, channel, event, title, body,
                 subject_type, subject_id, payload, to_address, status,
                 scheduled_for, created_at, updated_at)
             VALUES (:org, :uid, :channel, :event, :title, :body,
                     :stype, :sid, :payload, :to, \'queued\', :sched, :now, :now)',
            [
                'org'     => $data['organization_id'] ?? null,
                'uid'     => $data['user_id'],
                'channel' => $data['channel'],
                'event'   => $data['event'],
                'title'   => $data['title'],
                'body'    => $data['body'],
                'stype'   => $data['subject_type'] ?? null,
                'sid'     => $data['subject_id'] ?? null,
                'payload' => $data['payload'] ?? null,
                'to'      => $data['to_address'] ?? null,
                'sched'   => $data['scheduled_for'] ?? null,
                'now'     => now(),
            ],
        );
    }

    // ---------------------------------------------------------------
    // Inbox
    // ---------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function inbox(int $userId, bool $unreadOnly = false, int $limit = 50): array
    {
        $where = [
            'user_id = :uid',
            "channel = 'in_app'",
            'dismissed_at IS NULL',
            '(scheduled_for IS NULL OR scheduled_for <= UTC_TIMESTAMP())',
            'created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . self::INBOX_DAYS . ' DAY)',
        ];
        if ($unreadOnly) {
            $where[] = 'read_at IS NULL';
        }

        return Database::select(
            'SELECT id, event, title, body, subject_type, subject_id,
                    read_at, created_at
               FROM notifications
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY created_at DESC
              LIMIT ' . max(1, min(200, $limit)),
            ['uid' => $userId],
        );
    }

    public function unreadCount(int $userId): int
    {
        $row = Database::selectOne(
            'SELECT COUNT(*) AS c FROM notifications
              WHERE user_id = :uid AND channel = \'in_app\' AND read_at IS NULL
                AND dismissed_at IS NULL
                AND (scheduled_for IS NULL OR scheduled_for <= UTC_TIMESTAMP())',
            ['uid' => $userId],
        );

        return (int) ($row['c'] ?? 0);
    }

    /** Mark one notification read, or all of them when $id is null. */
    public function markRead(int $userId, ?int $id = null): int
    {
        $sql = 'UPDATE notifications SET read_at = :now, status = \'read\', updated_at = :now
                 WHERE user_id = :uid AND channel = \'in_app\' AND read_at IS NULL';
        $args = ['now' => now(), 'uid' => $userId];

        if ($id !== null) {
            $sql       .= ' AND id = :id';
            $args['id'] = $id;
        }

        return Database::statement($sql, $args);
    }

    /**
     * Hide notifications from one person's inbox.
     *
     * The rows are not deleted — "was the patient told?" has to stay
     * answerable, and the notification is the record of that.
     *
     * @param list<int>|null $ids specific rows to clear; null means the sweep
     */
    public function dismiss(int $userId, ?int $id = null, ?array $ids = null, bool $everything = false): int
    {
        $sql = 'UPDATE notifications SET dismissed_at = :now, updated_at = :now
                 WHERE user_id = :uid AND channel = \'in_app\' AND dismissed_at IS NULL';
        $args = ['now' => now(), 'uid' => $userId];

        if ($id !== null) {
            $sql       .= ' AND id = :id';
            $args['id'] = $id;
        } elseif ($ids !== null) {
            // A hand-picked set. The user_id predicate above still applies, so
            // an id belonging to somebody else simply matches nothing.
            $ids = array_values(array_unique(array_map('intval', $ids)));
            if ($ids === []) {
                return 0;
            }
            $placeholders = [];
            foreach ($ids as $i => $value) {
                $placeholders[]  = ':id' . $i;
                $args['id' . $i] = $value;
            }
            $sql .= ' AND id IN (' . implode(', ', $placeholders) . ')';
        } elseif (!$everything) {
            $sql .= ' AND read_at IS NOT NULL';
        }
        // $everything: no further predicate — the whole inbox goes, read or
        // not. The user asked for an empty inbox, and hiding half of it while
        // reporting success is the sort of "helpfulness" nobody wants.

        return Database::statement($sql, $args);
    }

    // ---------------------------------------------------------------
    // Delivery worker
    // ---------------------------------------------------------------

    /**
     * Everything due to be sent.
     *
     * `scheduled_for` in the future is not due yet — a reminder queued today
     * for tomorrow morning waits. Rows past the attempt ceiling are left alone
     * so the queue can actually drain.
     *
     * @return list<array<string,mixed>>
     */
    public function due(int $maxAttempts, int $limit = 100): array
    {
        return Database::select(
            'SELECT * FROM notifications
              WHERE status = \'queued\'
                AND attempts < :max
                AND (scheduled_for IS NULL OR scheduled_for <= UTC_TIMESTAMP())
              ORDER BY id
              LIMIT ' . max(1, min(500, $limit)),
            ['max' => $maxAttempts],
        );
    }

    /** A delivery that worked, or one the channel declined to attempt. */
    public function markSent(int $id, ?string $note): void
    {
        Database::statement(
            'UPDATE notifications
                SET status = \'sent\', sent_at = :now, error = :note,
                    attempts = attempts + 1, updated_at = :now
              WHERE id = :id',
            ['now' => now(), 'note' => $note, 'id' => $id],
        );
    }

    /**
     * A delivery that threw.
     *
     * Stays 'queued' while attempts remain, so the next run picks it up;
     * 'failed' once it has run out, so the queue does not retry for ever.
     */
    public function markAttemptFailed(int $id, int $attempts, string $error, bool $gaveUp): void
    {
        Database::statement(
            'UPDATE notifications
                SET attempts = :attempts, error = :error,
                    status = :status, updated_at = :now
              WHERE id = :id',
            [
                'attempts' => $attempts,
                'error'    => substr($error, 0, 500),
                'status'   => $gaveUp ? 'failed' : 'queued',
                'now'      => now(),
                'id'       => $id,
            ],
        );
    }
}
