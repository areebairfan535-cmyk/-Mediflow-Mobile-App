<?php
declare(strict_types=1);

namespace App\Services\Notifications;

/**
 * In-app notifications need no delivery at all: the patient app reads them
 * straight from the table. Marking them sent is what stops the worker from
 * looking at them again.
 */
final class InAppChannel implements Channel
{
    public function send(array $notification): string
    {
        return self::SENT;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'in_app';
    }
}
