<?php
declare(strict_types=1);

namespace App\Services\Billing;

/**
 * Tax is added on top of the listed price. Pakistan (GST), UAE (VAT), and the
 * common case in the USA.
 */
final class TaxExclusiveRule implements TaxRule
{
    public function __construct(private readonly string $label = 'Tax')
    {
    }

    public function apply(string $net, string $rate): array
    {
        $tax = Money::multiply($net, $rate);

        return [
            'taxable' => Money::round($net),
            'tax'     => $tax,
            'total'   => Money::add($net, $tax),
        ];
    }

    public function pricesIncludeTax(): bool
    {
        return false;
    }

    public function label(): string
    {
        return $this->label;
    }
}
