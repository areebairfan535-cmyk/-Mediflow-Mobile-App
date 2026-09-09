<?php
declare(strict_types=1);

namespace App\Services\Payments;

/**
 * Picks the payment gateway — the only place a provider name maps to a class.
 *
 * Same shape as AiProviders::resolve() and TaxRules::forCountry(): adding a
 * second provider is a class plus a line here, and nothing in the billing code
 * learns its name.
 *
 * A provider without credentials resolves to a NullGateway that says why,
 * rather than one that fails at the HTTP layer with the patient's finger still
 * on the button.
 */
final class PaymentGateways
{
    private static ?PaymentGateway $resolved = null;

    public static function resolve(): PaymentGateway
    {
        return self::$resolved ??= self::build();
    }

    /** Used by tests to force a gateway. */
    public static function fake(?PaymentGateway $gateway): void
    {
        self::$resolved = $gateway;
    }

    private static function build(): PaymentGateway
    {
        // §21 lets the platform admin choose the gateway from the panel. The
        // setting falls back to PAYMENT_GATEWAY, so an installation that has
        // never opened that screen behaves exactly as it did before.
        //
        // Only the choice is a setting. The credentials are still read from the
        // environment inside the gateway itself — a settings table is shown on
        // screens and copied into backups, and a live secret key belongs in
        // neither.
        $name = strtolower(trim(\App\Services\PlatformSettings::get('payment_gateway')));

        $gateway = match ($name) {
            'paypal' => new PayPalGateway(),
            // Approves everything, for development and the test suite — never
            // in a real deployment, where a payment nobody made would settle a
            // real invoice and the ledger would say it was paid.
            'stub' => env('APP_ENV', 'local') === 'production'
                ? new NullGateway('The stub payment gateway is refused in production.')
                : new StubGateway(),
            // Other providers slot in here; each implements PaymentGateway and
            // nothing above this line changes.
            ''      => new NullGateway(),
            default => new NullGateway("Unknown payment gateway \"$name\"."),
        };

        if (!$gateway->isConfigured()) {
            return new NullGateway((string) $gateway->unavailableReason());
        }

        return $gateway;
    }

    /**
     * What the app needs to decide whether to show a Pay button.
     *
     * @return array<string,mixed>
     */
    public static function status(): array
    {
        $gateway = self::resolve();
        $live    = $gateway instanceof PayPalGateway && $gateway->isLive();

        return [
            'configured' => $gateway->isConfigured(),
            'gateway'    => $gateway->name(),
            // A demo must be unmistakable. The app puts this on the button.
            'mode'       => $gateway->isConfigured() ? ($live ? 'live' : 'sandbox') : null,
            'reason'     => $gateway->unavailableReason(),
        ];
    }
}
