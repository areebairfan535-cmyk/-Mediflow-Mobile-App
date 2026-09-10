<?php
declare(strict_types=1);

namespace App\Services\Billing;

/**
 * Resolver for country billing rules — where a market's configuration turns
 * into tax behaviour (§13 Strategy, §23 "do not hard-code country behavior").
 *
 * It used to be a match statement on the country code, which meant §23 was
 * only half kept: `countries` held the RATE, and this class held the RULE.
 * A platform admin could open a market at runtime and set its rate, currency
 * and date format, but every such market silently got "tax added on top,
 * labelled Tax" — so opening Ireland or Australia, both VAT/GST-inclusive,
 * overcharged every invoice by the rate until somebody edited PHP.
 *
 * The mode and the label now live on the `countries` row with everything
 * else about a market. The map below survives only as a fallback for a row
 * that predates the column, or for a country with no row at all.
 */
final class TaxRules
{
    /**
     * Build the rule a configured market asks for.
     *
     * @param ?string $mode  'exclusive' | 'inclusive' | 'exempt'
     * @param ?string $label what the invoice should call it: GST, VAT, ...
     */
    public static function make(?string $mode, ?string $label, ?string $countryCode = null): TaxRule
    {
        if ($mode === null || $mode === '') {
            return self::forCountry($countryCode);
        }

        $name = ($label === null || $label === '') ? 'Tax' : $label;

        return match (strtolower($mode)) {
            'inclusive' => new TaxInclusiveRule($name),
            'exempt'    => new TaxExemptRule(),
            default     => new TaxExclusiveRule($name),
        };
    }

    /**
     * Fallback for an unconfigured market. Kept deliberately small: a new
     * market belongs in `countries`, not here.
     */
    public static function forCountry(?string $countryCode): TaxRule
    {
        return match (strtoupper((string) $countryCode)) {
            'PK'    => new TaxExclusiveRule('GST'),
            'AE'    => new TaxExclusiveRule('VAT'),
            'GB'    => new TaxInclusiveRule('VAT'),
            'US'    => new TaxExclusiveRule('Sales Tax'),
            default => new TaxExclusiveRule('Tax'),
        };
    }
}
