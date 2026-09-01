<?php
declare(strict_types=1);

namespace App\Services\Ai;

/**
 * The provider used when none is configured.
 *
 * It does not pretend, retry, or degrade quietly — it says plainly that no AI
 * provider is set up. That is the honest state for a deployment that has not
 * chosen one, and §26 puts the AI module outside the MVP, so this is the
 * expected default rather than an error condition.
 */
final class NullProvider implements AiProvider
{
    public function __construct(private readonly string $reason = 'No AI provider is configured.')
    {
    }

    public function completeJson(string $system, string $prompt, array $options = []): array
    {
        throw new AiUnavailable(
            $this->reason . ' Set AI_PROVIDER and its API key in backend/.env to enable '
            . 'the assistants. Everything else works without it.'
        );
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'none';
    }

    public function model(): ?string
    {
        return null;
    }
}
