<?php
declare(strict_types=1);

namespace App\Services\Ai;

use App\Core\HttpException;

/**
 * The AI could not answer.
 *
 * 503, not 500: the clinic's own system is fine, an optional external
 * assistant is not. §9 makes AI advisory, so nothing a clinician needs to do
 * should be blocked by this — the UI shows the message and the user carries on
 * by hand.
 */
final class AiUnavailable extends HttpException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 503, 'ai_unavailable');
    }
}
