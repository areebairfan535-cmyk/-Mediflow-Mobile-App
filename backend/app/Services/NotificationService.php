<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Service;

/**
 * Notification engine (§20).
 *
 * One queue for every channel — in-app, push, email, SMS, and WhatsApp later.
 * Callers raise an EVENT ("appointment.booked"); this decides which channels
 * to use and what the message says. A service that wanted to send an email
 * directly would have to know SMTP details, templates and the patient's
 * preferences — so none of them do.
 *
 * Rows are queued, not sent. Delivery is a separate worker (`notify:dispatch`),
 * because a clinic must not wait on an SMTP timeout to finish booking an
 * appointment. In-app notifications need no delivery at all: the patient app
 * reads them straight from this table.
 */
final class NotificationService extends Service
{
    /**
     * Event catalogue from §20, with the channels each one uses.
     *
     * `title` and `body` are sprintf templates filled from the payload.
     */
    private const EVENTS = [
        'appointment.booked' => [
            'channels' => ['in_app', 'push'],
            'title'    => 'Appointment confirmed',
            'body'     => 'Your appointment with %s is on %s.',
            'keys'     => ['doctor', 'when'],
        ],
        'appointment.reminder' => [
            'channels' => ['in_app', 'push', 'sms'],
            'title'    => 'Appointment tomorrow',
            'body'     => 'Reminder: %s at %s.',
            'keys'     => ['doctor', 'when'],
        ],
        'appointment.cancelled' => [
            'channels' => ['in_app', 'push'],
            'title'    => 'Appointment cancelled',
            'body'     => 'Your appointment on %s was cancelled. %s',
            'keys'     => ['when', 'reason'],
        ],
        'prescription.issued' => [
            'channels' => ['in_app', 'push'],
            'title'    => 'Prescription ready',
            'body'     => '%s issued a prescription with %s medicine(s).',
            'keys'     => ['doctor', 'count'],
        ],
        'invoice.issued' => [
            'channels' => ['in_app', 'push', 'email'],
            'title'    => 'New invoice',
            'body'     => 'Invoice %s for %s is ready.',
            'keys'     => ['invoice_no', 'amount'],
        ],
        'payment.received' => [
            'channels' => ['in_app', 'push'],
            'title'    => 'Payment received',
            'body'     => 'We received %s. Receipt %s.',
            'keys'     => ['amount', 'receipt_no'],
        ],
        'invoice.overdue' => [
            'channels' => ['in_app', 'push', 'sms'],
            'title'    => 'Invoice overdue',
            'body'     => 'Invoice %s for %s is past its due date.',
            'keys'     => ['invoice_no', 'amount'],
        ],
        'lab.result_ready' => [
            'channels' => ['in_app', 'push'],
            'title'    => 'Lab results ready',
            'body'     => 'Results for order %s are available.',
            'keys'     => ['order_no'],
        ],
        'claim.updated' => [
            'channels' => ['in_app'],
            'title'    => 'Insurance claim update',
            'body'     => 'Claim %s is now %s.',
            'keys'     => ['claim_no', 'status'],
        ],
    ];

    /**
     * Queue a notification for a patient.
     *
     * Silently does nothing when the patient has no linked login — a walk-in
     * with no app account is normal, not an error worth failing a booking over.
     *
     * @param array<string,mixed> $payload values for the template, plus
     *                            subject_type / subject_id
     */
    public function notifyPatient(int $patientId, string $event, array $payload = []): void
    {
        $definition = self::EVENTS[$event] ?? null;
        if ($definition === null) {
            error_log("[notify] unknown event: $event");
            return;
        }

        $patient = Database::selectOne(
            'SELECT p.id, p.user_id, p.email, p.phone
               FROM patients p
              WHERE p.organization_id = :org AND p.id = :id',
            ['org' => $this->requireOrganization(), 'id' => $patientId],
        );

        if ($patient === null || $patient['user_id'] === null) {
            return;
        }

        $body = $this->render($definition, $payload);

        foreach ($definition['channels'] as $channel) {
            // Only queue a channel we can actually reach.
            $to = match ($channel) {
                'email'          => $patient['email'],
                'sms', 'whatsapp' => $patient['phone'],
                default          => null,   // in_app / push go to the account
            };
            if (in_array($channel, ['email', 'sms', 'whatsapp'], true) && empty($to)) {
                continue;
            }

            $this->queue([
                'user_id'      => (int) $patient['user_id'],
                'channel'      => $channel,
                'event'        => $event,
                'title'        => $definition['title'],
                'body'         => $body,
                'subject_type' => $payload['subject_type'] ?? null,
                'subject_id'   => $payload['subject_id'] ?? null,
                'payload'      => $payload,
                'to_address'   => $to,
                'scheduled_for' => $payload['scheduled_for'] ?? null,
            ]);
        }
    }

    /** @param array<string,mixed> $data */
    private function queue(array $data): void
    {
        try {
            Database::statement(
                'INSERT INTO notifications
                    (organization_id, user_id, channel, event, title, body,
                     subject_type, subject_id, payload, to_address, status,
                     scheduled_for, created_at, updated_at)
                 VALUES (:org, :uid, :channel, :event, :title, :body,
                         :stype, :sid, :payload, :to, \'queued\', :sched, :now, :now)',
                [
                    'org'     => $this->requireOrganization(),
                    'uid'     => $data['user_id'],
                    'channel' => $data['channel'],
                    'event'   => $data['event'],
                    'title'   => $data['title'],
                    'body'    => $data['body'],
                    'stype'   => $data['subject_type'],
                    'sid'     => $data['subject_id'],
                    'payload' => json_encode($data['payload'], JSON_UNESCAPED_UNICODE),
                    'to'      => $data['to_address'],
                    'sched'   => $data['scheduled_for'],
                    'now'     => now(),
                ],
            );
        } catch (\Throwable $e) {
            // A notification must never break the thing it is describing.
            error_log('[notify] queue failed: ' . $e->getMessage());
        }
    }

    /** @param array<string,mixed> $definition */
    private function render(array $definition, array $payload): string
    {
        $values = [];
        foreach ($definition['keys'] as $key) {
            $values[] = (string) ($payload[$key] ?? '');
        }
        return trim(vsprintf($definition['body'], $values));
    }

    // ---------------- reads, for the patient app ----------------

    /** @return list<array<string,mixed>> */
    /**
     * What a person actually sees in their inbox.
     *
     * Two things are filtered out beyond the obvious: anything they dismissed,
     * and anything older than the retention window. Both hide rows without
     * deleting them — the record of "this patient was told" survives either
     * way, which is the whole point of keeping notifications (§20).
     */
    public const INBOX_DAYS = 90;

    public function inbox(int $userId, bool $unreadOnly = false, int $limit = 50): array
    {
        $where = [
            'user_id = :uid',
            "channel = 'in_app'",
            'dismissed_at IS NULL',
            "(scheduled_for IS NULL OR scheduled_for <= UTC_TIMESTAMP())",
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

    /**
     * Hide one notification, or every read one, from this person's inbox.
     *
     * Unread rows survive "clear read" on purpose: clearing an inbox should
     * never be the way a patient loses a reminder they have not looked at.
     */
    /**
     * @param list<int>|null $ids specific rows to clear; null means the sweep
     */
    public function dismiss(int $userId, ?int $id = null, ?array $ids = null, bool $everything = false): int
    {
        $sql = 'UPDATE notifications SET dismissed_at = :now, updated_at = :now
                 WHERE user_id = :uid AND channel = \'in_app\' AND dismissed_at IS NULL';
        $args = ['now' => now(), 'uid' => $userId];

        if ($id !== null) {
            $sql        .= ' AND id = :id';
            $args['id']  = $id;
        } elseif ($ids !== null) {
            // A hand-picked set. The user_id predicate above still applies, so
            // an id belonging to somebody else simply matches nothing.
            $ids = array_values(array_unique(array_map('intval', $ids)));
            if ($ids === []) {
                return 0;
            }
            $placeholders = [];
            foreach ($ids as $i => $value) {
                $placeholders[]      = ':id' . $i;
                $args['id' . $i]     = $value;
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
            $sql        .= ' AND id = :id';
            $args['id']  = $id;
        }

        return Database::statement($sql, $args);
    }
}
