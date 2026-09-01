<?php
declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Picks the AI provider — the only place a provider name maps to a class.
 *
 * Same shape as TaxRules::forCountry(): adding a provider is a class plus a
 * line here. Nothing in AiAssistantService knows which one answered.
 *
 * When the configured provider has no key, this returns a NullProvider that
 * explains why rather than a half-working one — so the assistants report
 * "not configured" instead of failing at the HTTP layer.
 */
final class AiProviders
{
    private static ?AiProvider $resolved = null;

    public static function resolve(): AiProvider
    {
        return self::$resolved ??= self::build();
    }

    /** Used by tests to force a provider. */
    public static function fake(?AiProvider $provider): void
    {
        self::$resolved = $provider;
    }

    private static function build(): AiProvider
    {
        $name = strtolower(trim((string) env('AI_PROVIDER', '')));

        $provider = match ($name) {
            'anthropic' => new AnthropicProvider(),
            // Offline, rules-only, for development and for the test suite —
            // never in a real deployment, where placeholder text in a chart is
            // the exact failure §9 exists to prevent.
            'stub' => env('APP_ENV', 'local') === 'production'
                ? new NullProvider('The stub AI provider is refused in production.')
                : new StubProvider(),
            // Other providers slot in here; each implements AiProvider and
            // nothing above this line changes.
            ''      => new NullProvider('No AI provider is configured.'),
            default => new NullProvider("Unknown AI provider \"$name\"."),
        };

        if (!$provider->isConfigured()) {
            return new NullProvider(
                sprintf('The %s provider has no API key.', $provider->name()),
            );
        }

        return $provider;
    }

    /** @return array<string,mixed> status for /ai/status and the UI. */
    public static function status(): array
    {
        $provider = self::resolve();

        return [
            'configured' => $provider->isConfigured(),
            'provider'   => $provider->name(),
            'model'      => $provider->model(),
            'assistants' => [
                'documentation' => 'Turn short notes into a structured clinical note draft',
                'billing'       => 'Suggest billable services from what was recorded',
                'claim'         => 'Flag missing documentation and rejection risk',
            ],
            // §9's hard rule, surfaced so a client cannot forget it.
            'policy' => 'AI output is always a draft. A person must review and '
                      . 'approve it before it becomes a clinical or billing record.',
        ];
    }
}
