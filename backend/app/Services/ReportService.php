<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Service;
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
    /**
     * Revenue summary for a date range.
     *
     * @return array<string,mixed>
     */
    public function summary(string $from, string $to): array
    {
        $org  = $this->requireOrganization();
        $args = ['org' => $org, 'from' => $from . ' 00:00:00', 'to' => $to . ' 23:59:59'];

        $billed = Database::selectOne(
            'SELECT
               COUNT(*)                                       AS invoice_count,
               COALESCE(SUM(subtotal), 0)                      AS subtotal,
               COALESCE(SUM(discount_total), 0)                AS discounts,
               COALESCE(SUM(tax_total), 0)                     AS tax,
               COALESCE(SUM(grand_total), 0)                   AS billed,
               COALESCE(SUM(paid_total), 0)                    AS collected,
               COALESCE(SUM(grand_total - paid_total), 0)      AS outstanding
             FROM invoices
            WHERE organization_id = :org
              AND status NOT IN (\'draft\', \'cancelled\')
              AND created_at BETWEEN :from AND :to',
            $args,
        ) ?? [];

        // Cash actually received in the window — not the same as invoices
        // raised in the window, because a January invoice can be paid in March.
        $received = Database::selectOne(
            'SELECT COUNT(*) AS payment_count, COALESCE(SUM(amount), 0) AS received
               FROM payments
              WHERE organization_id = :org AND status = \'succeeded\'
                AND created_at BETWEEN :from AND :to',
            $args,
        ) ?? [];

        $refunded = Database::selectOne(
            'SELECT COUNT(*) AS refund_count, COALESCE(SUM(amount), 0) AS refunded
               FROM refunds
              WHERE organization_id = :org AND status = \'completed\'
                AND refunded_at BETWEEN :from AND :to',
            $args,
        ) ?? [];

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
        return Database::select(
            'SELECT method, COUNT(*) AS count, COALESCE(SUM(amount), 0) AS total
               FROM payments
              WHERE organization_id = :org AND status = \'succeeded\'
                AND created_at BETWEEN :from AND :to
              GROUP BY method
              ORDER BY total DESC',
            ['org' => $this->requireOrganization(), 'from' => $from . ' 00:00:00', 'to' => $to . ' 23:59:59'],
        );
    }

    /** Which services earn the money. */
    public function topServices(string $from, string $to, int $limit = 15): array
    {
        return Database::select(
            'SELECT COALESCE(ii.service_code, \'(ad-hoc)\') AS code,
                    ii.description,
                    SUM(ii.quantity)   AS quantity,
                    SUM(ii.line_total) AS revenue
               FROM invoice_items ii
               JOIN invoices i ON i.id = ii.invoice_id
              WHERE ii.organization_id = :org
                AND i.status NOT IN (\'draft\', \'cancelled\')
                AND i.created_at BETWEEN :from AND :to
              GROUP BY code, ii.description
              ORDER BY revenue DESC
              LIMIT ' . max(1, min(100, $limit)),
            ['org' => $this->requireOrganization(), 'from' => $from . ' 00:00:00', 'to' => $to . ' 23:59:59'],
        );
    }

    /** Revenue per doctor, via the encounter each invoice came from. */
    public function byDoctor(string $from, string $to): array
    {
        return Database::select(
            'SELECT u.name AS doctor_name, d.specialty,
                    COUNT(DISTINCT i.id)             AS invoices,
                    COALESCE(SUM(i.grand_total), 0)  AS billed,
                    COALESCE(SUM(i.paid_total), 0)   AS collected
               FROM invoices i
               JOIN encounters e ON e.id = i.encounter_id
               JOIN doctors d    ON d.id = e.doctor_id
               JOIN users u      ON u.id = d.user_id
              WHERE i.organization_id = :org
                AND i.status NOT IN (\'draft\', \'cancelled\')
                AND i.created_at BETWEEN :from AND :to
              GROUP BY u.name, d.specialty
              ORDER BY billed DESC',
            ['org' => $this->requireOrganization(), 'from' => $from . ' 00:00:00', 'to' => $to . ' 23:59:59'],
        );
    }

    /**
     * Outstanding balances bucketed by how late they are — the report a
     * clinic chases money with.
     */
    public function agedReceivables(): array
    {
        $rows = Database::select(
            'SELECT i.id, i.invoice_no, i.grand_total, i.paid_total,
                    (i.grand_total - i.paid_total) AS balance,
                    i.due_date, i.status,
                    CONCAT(p.first_name, \' \', p.last_name) AS patient_name,
                    p.mrn, p.phone,
                    DATEDIFF(CURDATE(), COALESCE(i.due_date, DATE(i.created_at))) AS days_late
               FROM invoices i
               JOIN patients p ON p.id = i.patient_id
              WHERE i.organization_id = :org
                AND i.status IN (\'issued\', \'partially_paid\', \'overdue\')
                AND i.grand_total > i.paid_total
              ORDER BY days_late DESC',
            ['org' => $this->requireOrganization()],
        );

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
