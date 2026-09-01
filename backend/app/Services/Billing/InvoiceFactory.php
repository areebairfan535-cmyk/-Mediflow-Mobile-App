<?php
declare(strict_types=1);

namespace App\Services\Billing;

use App\Core\ValidationException;
use App\Repositories\ServiceRepository;

/**
 * Builds invoice lines and totals — the Factory Pattern §13 asks for.
 *
 * ---------------------------------------------------------------------------
 * The rule that matters: the CLIENT NEVER SENDS MONEY.
 * ---------------------------------------------------------------------------
 * A request names a service, a quantity and (optionally) a discount. Every
 * amount — unit price, tax rate, tax, line total, invoice totals — is looked
 * up and computed here. If the browser could post `grand_total`, the browser
 * could decide what the clinic gets paid.
 *
 * Resolution order for a line:
 *   unit price  ← service_prices row effective today for this country
 *   tax rate    ← service_prices.tax_rate, else organization, else country
 *   taxable?    ← services.is_taxable
 *   how tax applies ← the country's TaxRule (§13 Strategy, §23 no hard-coding)
 */
final class InvoiceFactory
{
    public function __construct(
        private readonly ServiceRepository $services,
        private readonly TaxRule $taxRule,
        private readonly ?int $countryId,
        private readonly string $currency,
        private readonly string $defaultTaxRate,
    ) {
    }

    /**
     * @param list<array<string,mixed>> $lines each: service_id | description,
     *                                   quantity?, discount_amount? | discount_percent?
     * @return array{items: list<array<string,mixed>>, totals: array<string,string>}
     */
    public function build(array $lines): array
    {
        if ($lines === []) {
            throw new ValidationException(['items' => ['An invoice needs at least one line.']]);
        }

        $items  = [];
        $errors = [];

        foreach (array_values($lines) as $index => $line) {
            $label = 'Line ' . ($index + 1);

            try {
                $items[] = $this->buildLine($line, $label);
            } catch (ValidationException $e) {
                foreach ($e->fields ?? [] as $messages) {
                    foreach ((array) $messages as $message) {
                        $errors[] = $message;
                    }
                }
            }
        }

        if ($errors !== []) {
            throw new ValidationException(['items' => $errors]);
        }

        return ['items' => $items, 'totals' => $this->totals($items)];
    }

    /**
     * @param array<string,mixed> $line
     * @return array<string,mixed>
     */
    private function buildLine(array $line, string $label): array
    {
        $quantity = Money::of($line['quantity'] ?? 1);
        if (Money::compare($quantity, '0') <= 0) {
            throw new ValidationException(['line' => ["$label: quantity must be greater than zero."]]);
        }

        $service   = null;
        $unitPrice = null;
        $taxRate   = null;
        $taxable   = true;
        $maxDiscountPct = null;

        if (!empty($line['service_id'])) {
            $service = $this->services->find((int) $line['service_id']);
            if ($service === null) {
                throw new ValidationException(['line' => ["$label: that service does not exist."]]);
            }
            if ((int) $service['is_active'] !== 1) {
                throw new ValidationException(
                    ['line' => ["$label: “{$service['name']}” is no longer offered."]]
                );
            }

            $price = $this->services->effectivePrice((int) $service['id'], $this->countryId);
            if ($price === null) {
                // Refuse rather than invent a number — a zero-priced invoice
                // line is worse than a clear error.
                throw new ValidationException(
                    ['line' => ["$label: “{$service['name']}” has no price configured for this market."]]
                );
            }
            if (strtoupper((string) $price['currency_code']) !== strtoupper($this->currency)) {
                throw new ValidationException([
                    'line' => ["$label: “{$service['name']}” is priced in {$price['currency_code']}, "
                              . "but this invoice is in {$this->currency}."],
                ]);
            }

            $unitPrice      = Money::of($price['price']);
            $taxRate        = $price['tax_rate'] !== null ? Money::of($price['tax_rate']) : null;
            $taxable        = (int) $service['is_taxable'] === 1;
            $maxDiscountPct = Money::of($price['max_discount_pct'] ?? 0);
        } else {
            // Ad-hoc line. Still not client-priced money in the dangerous
            // sense: it is an explicit charge a biller typed, and it is
            // recorded as such.
            if (empty($line['description'])) {
                throw new ValidationException(
                    ['line' => ["$label: needs either a service or a description."]]
                );
            }
            if (!isset($line['unit_price'])) {
                throw new ValidationException(['line' => ["$label: an ad-hoc line needs a unit price."]]);
            }
            $unitPrice = Money::of($line['unit_price']);
            if (Money::isNegative($unitPrice)) {
                throw new ValidationException(['line' => ["$label: price cannot be negative."]]);
            }
        }

        $rate  = $taxable ? ($taxRate ?? $this->defaultTaxRate) : '0';
        $gross = Money::multiply($unitPrice, $quantity);

        // Discount: an explicit amount, or a percentage of the line.
        $discount = '0';
        if (isset($line['discount_percent']) && (string) $line['discount_percent'] !== '') {
            $pct = Money::of($line['discount_percent']);
            if (Money::isNegative($pct) || Money::compare($pct, '100') > 0) {
                throw new ValidationException(['line' => ["$label: discount must be between 0 and 100%."]]);
            }
            if ($maxDiscountPct !== null
                && !Money::isZero($maxDiscountPct)
                && Money::compare($pct, $maxDiscountPct) > 0
            ) {
                throw new ValidationException([
                    'line' => ["$label: discount above the {$maxDiscountPct}% allowed for this service."],
                ]);
            }
            $discount = Money::percentOf($gross, $pct);
        } elseif (isset($line['discount_amount']) && (string) $line['discount_amount'] !== '') {
            $discount = Money::of($line['discount_amount']);
            if (Money::isNegative($discount)) {
                throw new ValidationException(['line' => ["$label: discount cannot be negative."]]);
            }
        }

        if (Money::greaterThan($discount, $gross)) {
            throw new ValidationException(
                ['line' => ["$label: discount is larger than the line amount."]]
            );
        }

        $net    = Money::subtract($gross, $discount);
        $result = $this->taxRule->apply($net, $rate);

        return [
            'service_id'      => $service['id'] ?? null,
            'service_code'    => $service['code'] ?? null,
            'description'     => (string) ($line['description'] ?? $service['name']),
            'quantity'        => Money::round($quantity),
            'unit_price'      => Money::round($unitPrice),
            'discount_amount' => Money::round($discount),
            'tax_rate'        => $rate,
            'tax_amount'      => Money::round($result['tax']),
            'line_total'      => Money::round($result['total']),
            'procedure_id'    => $line['procedure_id'] ?? null,
            'lab_order_id'    => $line['lab_order_id'] ?? null,
            'is_ai_suggested' => !empty($line['is_ai_suggested']),
            // Kept for the totals pass; not a column.
            '_taxable_base'   => $result['taxable'],
        ];
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,string>
     */
    private function totals(array $items): array
    {
        $subtotal = Money::sum(array_column($items, '_taxable_base'));
        $discount = Money::sum(array_column($items, 'discount_amount'));
        $tax      = Money::sum(array_column($items, 'tax_amount'));
        $grand    = Money::sum(array_column($items, 'line_total'));

        return [
            'subtotal'       => Money::round($subtotal),
            'discount_total' => Money::round($discount),
            'tax_total'      => Money::round($tax),
            'grand_total'    => Money::round($grand),
        ];
    }

    /** Drop the internal key before the rows reach the repository. */
    public static function stripInternals(array $items): array
    {
        return array_map(
            static function (array $item): array {
                unset($item['_taxable_base']);
                return $item;
            },
            $items,
        );
    }
}
