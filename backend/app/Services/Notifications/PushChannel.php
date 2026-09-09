<?php
declare(strict_types=1);

namespace App\Services\Notifications;

use App\Repositories\DeviceTokenRepository;

/**
 * Push, out to the phone's own notification tray (§20).
 *
 * This was a stub that reported SKIPPED for every message — honestly, because
 * there was nowhere to send one. device_tokens is that somewhere, and this is
 * the half that uses it.
 *
 * Delivery goes through Expo's push service, which fans out to APNs and FCM.
 * That is the right hop for an Expo app: the alternative is holding Apple
 * certificates and a Firebase project to do what Expo already does, and the
 * token the app hands us is an Expo one either way.
 *
 * It needs no credentials, which is why this reports itself configured unless
 * the deployment turns it off. What it does NOT do is invent a delivery for
 * somebody who never installed the app — they have no device, and that is
 * SKIPPED.
 */
final class PushChannel implements Channel
{
    private const ENDPOINT = 'https://exp.host/--/api/v2/push/send';

    private DeviceTokenRepository $devices;
    private string $endpoint;

    public function __construct(?DeviceTokenRepository $devices = null)
    {
        $this->devices = $devices ?? new DeviceTokenRepository();
        // Overridable so a test can point this somewhere local rather than
        // firing real notifications at real phones.
        $this->endpoint = (string) (env('PUSH_ENDPOINT') ?: self::ENDPOINT);
    }

    public function name(): string
    {
        return 'push';
    }

    /**
     * True unless the deployment has deliberately turned push off.
     *
     * Whether a *particular* message can be delivered depends on that person
     * having a device registered, which is answered per message in send().
     */
    public function isConfigured(): bool
    {
        return strtolower((string) env('PUSH_ENABLED', 'true')) !== 'false';
    }

    public function send(array $notification): string
    {
        if (!$this->isConfigured()) {
            return self::SKIPPED;
        }

        $userId = (int) ($notification['user_id'] ?? 0);
        if ($userId <= 0) {
            return self::SKIPPED;
        }

        $tokens = $this->devices->liveTokensFor($userId);
        if ($tokens === []) {
            // No phone registered. Not a failure — plenty of people never
            // install the app — and retrying it every minute for ever would
            // be noise.
            return self::SKIPPED;
        }

        $messages = [];
        foreach ($tokens as $token) {
            $messages[] = [
                'to'    => $token,
                'title' => (string) ($notification['title'] ?? 'MediFlow'),
                'body'  => (string) ($notification['body'] ?? ''),
                'sound' => 'default',
                // What the app should open when the notification is tapped.
                // The subject is already on the row; passing it through turns
                // "you have a new invoice" into a screen showing that invoice.
                'data'  => [
                    'event'           => $notification['event'] ?? null,
                    'subject_type'    => $notification['subject_type'] ?? null,
                    'subject_id'      => $notification['subject_id'] ?? null,
                    'notification_id' => $notification['id'] ?? null,
                ],
            ];
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'timeout'       => 15,
                'ignore_errors' => true,
                'header'        => "Content-Type: application/json\r\n"
                                 . "Accept: application/json\r\n",
                'content'       => json_encode($messages, JSON_UNESCAPED_UNICODE),
            ],
        ]);

        $response = @file_get_contents($this->endpoint, false, $context);
        $status   = $this->statusFrom($http_response_header ?? []);

        if ($response === false || $status >= 400) {
            throw new \RuntimeException("Push service returned $status");
        }

        $this->retireDeadTokens($tokens, (string) $response);

        return self::SENT;
    }

    /**
     * A token the service calls dead is retired, not retried.
     *
     * Expo answers DeviceNotRegistered once the app has been uninstalled or
     * the token replaced. Pushing to it again cannot succeed, and a queue that
     * keeps trying is a queue that never drains — so the row is revoked with
     * the reason written on it.
     *
     * @param list<string> $tokens in the order they were sent
     */
    private function retireDeadTokens(array $tokens, string $response): void
    {
        $body = json_decode($response, true);
        if (!is_array($body) || !is_array($body['data'] ?? null)) {
            return;
        }

        foreach (array_values($body['data']) as $i => $result) {
            if (!is_array($result) || ($result['status'] ?? '') !== 'error') {
                continue;
            }
            $reason = (string) ($result['details']['error'] ?? $result['message'] ?? 'push error');

            if (isset($tokens[$i]) && $reason === 'DeviceNotRegistered') {
                $this->devices->revoke($tokens[$i], 'Device is no longer registered');
            }
        }
    }

    /** @param list<string> $headers */
    private function statusFrom(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                return (int) $m[1];
            }
        }
        return 0;
    }
}
