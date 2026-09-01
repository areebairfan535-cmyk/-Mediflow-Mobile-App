<?php
declare(strict_types=1);

namespace App\Services\Billing;

/** Healthcare is tax-exempt in this market. */
final class TaxExemptRule implements TaxRule
{
    public function apply(string $net, string $rate): array
    {
        return [
            'taxable' => Money::round($net),
            'tax'     => '0.00',
            'total'   => Money::round($net),
        ];
    }

    public function pricesIncludeTax(): bool
    {
        return false;
    }

    public function label(): string
    {
        return 'Exempt';
    }
}
