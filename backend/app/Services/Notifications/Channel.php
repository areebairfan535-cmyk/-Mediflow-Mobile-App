<?php
declare(strict_types=1);

namespace App\Services\Notifications;

/**
 * How a queued notification actually leaves the building (§20).
 *
 * §13 asks for the Strategy pattern here for the same reason as tax rules and
 * AI providers: a clinic in Karachi will send SMS through a local gateway and
 * one in London will not send SMS at all, and neither should require touching
 * the code that decides *what* to say.
 *
 * Every channel reports one of three things, and the difference matters to the
 * worker:
 *
 *   SENT        it left. Mark the row sent.
 *   SKIPPED     nothing is configured for this channel — not a failure, and
 *               retrying it every minute for ever would be pointless noise.
 *   FAILED      it was configured and it did not work. Worth retrying.
 */
interface Channel
{
    public const SENT    = 'sent';
    public const SKIPPED = 'skipped';
    public const FAILED  = 'failed';

    /** @param array<string,mixed> $notification the queued row */
    public function send(array $notification): string;

    /** False when the credentials for this channel are not configured. */
    public function isConfigured(): bool;

    public function name(): string;
}
