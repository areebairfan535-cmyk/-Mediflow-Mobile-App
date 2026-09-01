<?php
declare(strict_types=1);

namespace App\Services\Notifications;

use App\Core\Database;

/**
 * Sends what NotificationService queued (§20).
 *
 * Queue and delivery are separate on purpose: booking an appointment must not
 * wait on an SMTP handshake, and an SMS gateway being down must not roll back
 * a consultation. This is the other half of that split, and it is the only
 * place that talks to the outside world.
 *
 * Three rules it works to:
 *
 *  - **Nothing before its time.** A reminder queued today for tomorrow morning
 *    carries `scheduled_for`; it is not due until then.
 *  - **Give up eventually.** A row that has failed MAX_ATTEMPTS times is marked
 *    failed and left alone. A queue that retries for ever quietly becomes a
 *    queue that never drains.
 *  - **A dead channel is not a dead notification.** With no SMTP configured
 *    the email copy is skipped, and the in-app copy the patient actually reads
 *    is unaffected.
 */
final class Dispatcher
{
    public const MAX_ATTEMPTS = 5;

    /** @var array<string,Channel> */
    private array $channels;

    public function __construct(?array $channels = null)
    {
        $this->channels = $channels ?? [
            'in_app' => new InAppChannel(),
            'email'  => new SmtpChannel(),
            'sms'    => new SmsChannel(),
            'push'   => new PushChannel(),
            // WhatsApp is §20's "future"; it slots in here as one more class.
        ];
    }

    /**
     * Send everything that is due.
     *
     * @return array<string,int> counts by outcome, for the CLI to print
     */
    public function run(int $limit = 100): array
    {
        $due = Database::select(
            'SELECT * FROM notifications
              WHERE status = \'queued\'
                AND attempts < :max
                AND (scheduled_for IS NULL OR scheduled_for <= UTC_TIMESTAMP())
              ORDER BY id
              LIMIT ' . max(1, min(500, $limit)),
            ['max' => self::MAX_ATTEMPTS],
        );

        $counts = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'gave_up' => 0];

        foreach ($due as $notification) {
            $channel = $this->channels[$notification['channel']] ?? null;

            if ($channel === null) {
                $this->finish($notification, Channel::SKIPPED, 'No handler for this channel.');
                $counts['skipped']++;
                continue;
            }

            try {
                $result = $channel->send($notification);
            } catch (\Throwable $e) {
                $attempts = (int) $notification['attempts'] + 1;
                $gaveUp   = $attempts >= self::MAX_ATTEMPTS;

                Database::statement(
                    'UPDATE notifications
                        SET attempts = :attempts, error = :error,
                            status = :status, updated_at = :now
                      WHERE id = :id',
                    [
                        'attempts' => $attempts,
                        'error'    => substr($e->getMessage(), 0, 500),
                        // Still queued while there are attempts left; the next
                        // run picks it up.
                        'status'   => $gaveUp ? 'failed' : 'queued',
                        'now'      => now(),
                        'id'       => (int) $notification['id'],
                    ],
                );

                $counts[$gaveUp ? 'gave_up' : 'failed']++;
                continue;
            }

            $this->finish($notification, $result);
            $counts[$result === Channel::SENT ? 'sent' : 'skipped']++;
        }

        return $counts;
    }

    /**
     * A channel with no credentials is reported once, so an operator can see at
     * a glance why nothing is going out.
     *
     * @return array<string,bool>
     */
    public function status(): array
    {
        $status = [];
        foreach ($this->channels as $name => $channel) {
            $status[$name] = $channel->isConfigured();
        }
        return $status;
    }

    /** @param array<string,mixed> $notification */
    private function finish(array $notification, string $result, ?string $note = null): void
    {
        // SKIPPED is recorded as 'sent' with a note rather than left queued:
        // the row is finished with either way, and leaving it queued would mean
        // re-examining it on every run for ever.
        Database::statement(
            'UPDATE notifications
                SET status = \'sent\', sent_at = :now, error = :note,
                    attempts = attempts + 1, updated_at = :now
              WHERE id = :id',
            [
                'now'  => now(),
                'note' => $result === Channel::SKIPPED
                    ? ($note ?? 'Channel not configured — nothing was sent.')
                    : null,
                'id'   => (int) $notification['id'],
            ],
        );
    }
}
