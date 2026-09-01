<?php
declare(strict_types=1);

namespace App\Services\Ai;

/**
 * The AI provider contract — §13's Strategy Pattern applied to §9's AI module.
 *
 * The development plan describes what the AI assistants must DO (§9) but names
 * no provider anywhere, including in the §24 technology stack. That is a
 * deliberate gap, so it is modelled as one: the assistants talk to this
 * interface, and which model actually answers is a configuration choice.
 *
 * Consequences that matter:
 *   - the app runs with NO provider configured (see NullProvider) — every
 *     assistant degrades to "not configured" and nothing else breaks;
 *   - swapping providers, or adding a self-hosted model, is a new class plus
 *     a line in AiProviders::resolve() — no assistant changes.
 */
interface AiProvider
{
    /**
     * Ask the model for a JSON object.
     *
     * Implementations must return decoded JSON or throw AiUnavailable. They
     * must NOT invent a result when the provider is unreachable — a fabricated
     * clinical note is far worse than a visible failure.
     *
     * @param string $system      the assistant's role and rules
     * @param string $prompt      the case at hand
     * @param array<string,mixed> $options max_tokens, temperature-free hints
     * @return array<string,mixed> decoded JSON object
     * @throws AiUnavailable
     */
    public function completeJson(string $system, string $prompt, array $options = []): array;

    /** Is this provider actually usable right now? */
    public function isConfigured(): bool;

    /** Human-readable name for the UI and for audit rows. */
    public function name(): string;

    /** The model identifier actually used, or null when not configured. */
    public function model(): ?string;
}
