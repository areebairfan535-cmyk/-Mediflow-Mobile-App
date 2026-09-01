<?php
declare(strict_types=1);

namespace App\Services\Billing;

/**
 * Resolver for country billing rules — the only place a country code maps to
 * tax behaviour (§13 Strategy, §23 "do not hard-code country behavior").
 *
 * Adding a market means adding a line here and a row in `countries`, not
 * touching InvoiceService.
 */
final class TaxRules
{
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
