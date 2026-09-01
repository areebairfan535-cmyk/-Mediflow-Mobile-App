<?php
declare(strict_types=1);

namespace App\Services\Billing;

/**
 * Country billing rules — the Strategy Pattern of §13, applied to the problem
 * §23 names: "do not hard-code country behavior".
 *
 * A rule decides how tax turns a line's net amount into a gross one. The
 * default is what most markets do (tax added on top of the net price), and a
 * market that works differently gets its own implementation rather than an
 * `if` branch inside the invoice service.
 *
 * Implementations: TaxExclusiveRule, TaxInclusiveRule, TaxExemptRule.
 * TaxRules::forCountry() picks one.
 */
interface TaxRule
{
    /**
     * @param string $net  line net after discount, as a decimal string
     * @param string $rate tax rate as a decimal fraction, e.g. "0.1700"
     * @return array{taxable: string, tax: string, total: string}
     */
    public function apply(string $net, string $rate): array;

    /** Whether prices in the catalogue already include tax. */
    public function pricesIncludeTax(): bool;

    public function label(): string;
}
