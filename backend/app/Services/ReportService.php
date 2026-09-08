<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Service;
use App\Repositories\ReportRepository;
use App\Services\Billing\Money;

/**
 * Financial reports (§25 Phase 3, §21 dashboard figures).
 *
 * Every figure is read from the ledger, never from a running total kept
 * elsewhere. "Collected" is what payments say, "billed" is what issued
 * invoices say, and "outstanding" is the difference — so the three can be
 * reconciled against each other rather than trusted separately.
 */
final class ReportService extends Service
{
    private function reports(): ReportRepository
    {
        return (new ReportRepository())->forOrganization($this->requireOrganization());
    }

    /**
     * Revenue summary for a date range.
     *
     * @return array<string,mixed>
     */
    public function summary(string $from, string $to): array
    {
        $org     = $this->requireOrganization();
        $reports = $this->reports();

        $billed = $reports->billedBetween($from, $to);

        // Cash actually received in the window — not the same as invoices
        // raised in the window, because a January invoice can be paid in March.
        $received = $reports->receivedBetween($from, $to);
        $refunded = $reports->refundedBetween($from, $to);

        $currency = (new \App\Repositories\OrganizationRepository())
            ->settings($org)['currency_code'] ?? 'USD';

        return [
            'from'     => $from,
            'to'       => $to,
            'currency' => $currency,
            'invoices' => [
                'count'       => (int) ($billed['invoice_count'] ?? 0),
                'subtotal'    => Money::round($billed['subtotal']    ?? 0),
                'discounts'   => Money::round($billed['discounts']   ?? 0),
                'tax'         => Money::round($billed['tax']         ?? 0),
                'billed'      => Money::round($billed['billed']      ?? 0),
                'collected'   => Money::round($billed['collected']   ?? 0),
                'outstanding' => Money::round($billed['outstanding'] ?? 0),
            ],
            'cash' => [
                'payments' => (int) ($received['payment_count'] ?? 0),
                'received' => Money::round($received['received'] ?? 0),
                'refunds'  => (int) ($refunded['refund_count'] ?? 0),
                'refunded' => Money::round($refunded['refunded'] ?? 0),
                'net'      => Money::round(
                    Money::subtract($received['received'] ?? 0, $refunded['refunded'] ?? 0),
                ),
            ],
        ];
    }

    /** Cash taken, split by method — what a day-end till reconciliation needs (§7). */
    public function byPaymentMethod(string $from, string $to): array
    {
        return $this->reports()->paymentMethodMix($from, $to);
    }

    /** Which services earn the money. */
    public function topServices(string $from, string $to, int $limit = 15): array
    {
        return $this->reports()->topServices($from, $to, $limit);
    }

    /** Revenue per doctor, via the encounter each invoice came from. */
    public function byDoctor(string $from, string $to): array
    {
        return $this->reports()->revenueByDoctor($from, $to);
    }

    /**
     * Outstanding balances bucketed by how late they are — the report a
     * clinic chases money with.
     */
    public function agedReceivables(): array
    {
        $rows = $this->reports()->openBalances();

        $buckets = [
            'current'  => ['label' => 'Not yet due', 'count' => 0, 'total' => '0'],
            'days_30'  => ['label' => '1-30 days',   'count' => 0, 'total' => '0'],
            'days_60'  => ['label' => '31-60 days',  'count' => 0, 'total' => '0'],
            'days_90'  => ['label' => '61-90 days',  'count' => 0, 'total' => '0'],
            'over_90'  => ['label' => 'Over 90 days','count' => 0, 'total' => '0'],
        ];

        foreach ($rows as $row) {
            $days = (int) $row['days_late'];
            $key  = match (true) {
                $days <= 0  => 'current',
                $days <= 30 => 'days_30',
                $days <= 60 => 'days_60',
                $days <= 90 => 'days_90',
                default     => 'over_90',
            };
            $buckets[$key]['count']++;
            $buckets[$key]['total'] = Money::add($buckets[$key]['total'], $row['balance']);
        }

        foreach ($buckets as $key => $bucket) {
            $buckets[$key]['total'] = Money::round($bucket['total']);
        }

        return ['buckets' => $buckets, 'invoices' => $rows];
    }
}
