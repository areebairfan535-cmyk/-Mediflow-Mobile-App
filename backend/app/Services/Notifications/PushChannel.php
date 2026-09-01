<?php
declare(strict_types=1);

namespace App\Services\Notifications;

/**
 * Push, which needs a device-token store this product does not have yet.
 *
 * It reports SKIPPED rather than pretending: a channel that silently swallows
 * messages is worse than one that says it is not set up, because the first
 * looks like it is working.
 */
final class PushChannel implements Channel
{
    public function send(array $notification): string
    {
        return self::SKIPPED;
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'push';
    }
}
