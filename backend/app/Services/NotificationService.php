<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Service;
use App\Repositories\NotificationRepository;
use App\Repositories\PatientRepository;

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
    private function notifications(): NotificationRepository
    {
        return new NotificationRepository();
    }

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
        // A move is one event, not a cancellation followed by a booking: two
        // messages for one decision read as two decisions, and the first of
        // them ("cancelled") is alarming on its own.
        'appointment.rescheduled' => [
            'channels' => ['in_app', 'push'],
            'title'    => 'Appointment moved',
            'body'     => 'Your appointment with %s is now on %s.',
            'keys'     => ['doctor', 'when'],
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

        // ---- the doctor's side of the same appointments ----
        //
        // A patient booking from their phone used to reach the doctor only
        // when the doctor happened to look at the right day. These are the
        // same three events, addressed to the person the time was taken from.
        'appointment.booked.doctor' => [
            'channels' => ['in_app', 'push'],
            'title'    => 'New appointment',
            'body'     => '%s booked %s with you.',
            'keys'     => ['patient', 'when'],
        ],
        'appointment.rescheduled.doctor' => [
            'channels' => ['in_app', 'push'],
            'title'    => 'Appointment moved',
            'body'     => '%s moved their appointment to %s.',
            'keys'     => ['patient', 'when'],
        ],
        'appointment.cancelled.doctor' => [
            'channels' => ['in_app', 'push'],
            'title'    => 'Appointment cancelled',
            'body'     => '%s cancelled their appointment on %s. %s',
            'keys'     => ['patient', 'when', 'reason'],
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

        $patient = (new PatientRepository())
            ->forOrganization($this->requireOrganization())
            ->contactFor($patientId);

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

    /**
     * Queue a notification for a staff account — a doctor, mostly.
     *
     * Staff are reached in the app and by push only; a clinic does not text
     * its own doctors about bookings. The templates are the same catalogue,
     * so the doctor's "new appointment" is filled and stored exactly like the
     * patient's "appointment confirmed", and the inbox reads both the same.
     *
     * @param array<string,mixed> $payload values for the template, plus
     *                            subject_type / subject_id
     */
    public function notifyUser(int $userId, string $event, array $payload = []): void
    {
        $definition = self::EVENTS[$event] ?? null;
        if ($definition === null) {
            error_log("[notify] unknown event: $event");
            return;
        }

        $body = $this->render($definition, $payload);

        foreach ($definition['channels'] as $channel) {
            if (!in_array($channel, ['in_app', 'push'], true)) {
                continue;
            }
            $this->queue([
                'user_id'       => $userId,
                'channel'       => $channel,
                'event'         => $event,
                'title'         => $definition['title'],
                'body'          => $body,
                'subject_type'  => $payload['subject_type'] ?? null,
                'subject_id'    => $payload['subject_id'] ?? null,
                'payload'       => $payload,
                'to_address'    => null,
                'scheduled_for' => $payload['scheduled_for'] ?? null,
            ]);
        }
    }

    /** @param array<string,mixed> $data */
    private function queue(array $data): void
    {
        try {
            // Overrides on the left: `+` keeps the left-hand keys, and the
            // payload must reach the row encoded rather than as an array.
            $this->notifications()->queue([
                'organization_id' => $this->requireOrganization(),
                'payload'         => json_encode($data['payload'], JSON_UNESCAPED_UNICODE),
            ] + $data);
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
        return $this->notifications()->inbox($userId, $unreadOnly, $limit);
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
        return $this->notifications()->dismiss($userId, $id, $ids, $everything);
    }

    public function unreadCount(int $userId): int
    {
        return $this->notifications()->unreadCount($userId);
    }

    /** Mark one notification read, or all of them when $id is null. */
    public function markRead(int $userId, ?int $id = null): int
    {
        return $this->notifications()->markRead($userId, $id);
    }
}
