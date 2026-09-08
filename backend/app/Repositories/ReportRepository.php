<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Repository;
use App\Models\Invoice;

/**
 * The aggregates behind the revenue reports (§6), moved out of ReportService
 * per §18.
 *
 * Every method takes a date range as two bound values rather than building one
 * from "today", because a report has to be reproducible: the same range must
 * give the same numbers tomorrow.
 */
final class ReportRepository extends Repository
{
    protected string $model = Invoice::class;

    /**
     * What was billed in a window.
     *
     * Drafts and cancelled invoices are excluded: a draft is not a bill, and a
     * cancelled one is a bill that was withdrawn.
     *
     * @return array<string,mixed>
     */
    public function billedBetween(string $from, string $to): array
    {
        return $this->query(
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
            $this->range($from, $to),
        )[0] ?? [];
    }

    /**
     * Cash actually received in the window.
     *
     * Deliberately not the same question as billedBetween(): a January invoice
     * can be paid in March, and a clinic asking "what came in" means the money,
     * not the paperwork.
     *
     * @return array<string,mixed>
     */
    public function receivedBetween(string $from, string $to): array
    {
        return $this->query(
            'SELECT COUNT(*) AS payment_count, COALESCE(SUM(amount), 0) AS received
               FROM payments
              WHERE organization_id = :org AND status = \'succeeded\'
                AND created_at BETWEEN :from AND :to',
            $this->range($from, $to),
        )[0] ?? [];
    }

    /** @return array<string,mixed> */
    public function refundedBetween(string $from, string $to): array
    {
        return $this->query(
            'SELECT COUNT(*) AS refund_count, COALESCE(SUM(amount), 0) AS refunded
               FROM refunds
              WHERE organization_id = :org AND status = \'completed\'
                AND refunded_at BETWEEN :from AND :to',
            $this->range($from, $to),
        )[0] ?? [];
    }

    /** @return list<array<string,mixed>> */
    public function paymentMethodMix(string $from, string $to): array
    {
        return $this->query(
            'SELECT method, COUNT(*) AS count, COALESCE(SUM(amount), 0) AS total
               FROM payments
              WHERE organization_id = :org AND status = \'succeeded\'
                AND created_at BETWEEN :from AND :to
              GROUP BY method
              ORDER BY total DESC',
            $this->range($from, $to),
        );
    }

    /** Which services earn the money. @return list<array<string,mixed>> */
    public function topServices(string $from, string $to, int $limit = 15): array
    {
        return $this->query(
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
            $this->range($from, $to),
        );
    }

    /** Revenue per doctor, via the encounter each invoice came from. @return list<array<string,mixed>> */
    public function revenueByDoctor(string $from, string $to): array
    {
        return $this->query(
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
            $this->range($from, $to),
        );
    }

    /**
     * Every unpaid invoice with how late it is — the raw rows the aged
     * receivables report buckets.
     *
     * Invoices with no due date fall back to their creation date, so a bill
     * nobody dated still ages rather than sitting in "not yet due" for ever.
     *
     * @return list<array<string,mixed>>
     */
    public function openBalances(): array
    {
        return $this->query(
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
            ['org' => $this->scopeBinding()],
        );
    }

    /**
     * A whole-day range: dates in, DATETIME bounds out.
     *
     * @return array{org:int, from:string, to:string}
     */
    private function range(string $from, string $to): array
    {
        return [
            'org'  => $this->scopeBinding(),
            'from' => $from . ' 00:00:00',
            'to'   => $to . ' 23:59:59',
        ];
    }
}
