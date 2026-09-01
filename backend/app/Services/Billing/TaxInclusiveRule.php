<?php
declare(strict_types=1);

namespace App\Services\Billing;

/**
 * The listed price already contains tax, so the tax is extracted from it
 * rather than added. UK retail pricing works this way.
 *
 *   net_excl = gross / (1 + rate)
 *   tax      = gross - net_excl
 */
final class TaxInclusiveRule implements TaxRule
{
    public function __construct(private readonly string $label = 'VAT')
    {
    }

    public function apply(string $gross, string $rate): array
    {
        $net = Money::divide($gross, Money::add('1', $rate));
        $tax = Money::subtract($gross, $net);

        return [
            'taxable' => Money::round($net),
            'tax'     => Money::round($tax),
            'total'   => Money::round($gross),
        ];
    }

    public function pricesIncludeTax(): bool
    {
        return true;
    }

    public function label(): string
    {
        return $this->label;
    }
}
