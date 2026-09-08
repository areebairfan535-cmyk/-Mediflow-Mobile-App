<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Repository;
use App\Models\Payment;

/**
 * The payment ledger (§7).
 *
 * This SQL used to live in PaymentService. §18 puts persistence in the
 * repository and leaves the service the rules — which of the two a line of
 * code is doing is usually obvious: "may this invoice take payment" is a rule,
 * "write the row" is not.
 */
final class PaymentRepository extends Repository
{
    protected string $model = Payment::class;

    /**
     * The next receipt number for this clinic.
     *
     * Per-organization, like the MRN: two clinics both having RCT-000001 is
     * correct. Taken inside the caller's transaction.
     */
    public function nextReceiptNo(): string
    {
        $row = $this->query(
            'SELECT COALESCE(MAX(CAST(SUBSTRING(receipt_no, 5) AS UNSIGNED)), 0) AS n
               FROM payments
              WHERE organization_id = :org AND receipt_no REGEXP \'^RCT-[0-9]+$\'',
            ['org' => $this->scopeBinding()],
        )[0] ?? [];

        return sprintf('RCT-%06d', ((int) ($row['n'] ?? 0)) + 1);
    }

    /**
     * One payment, with everything a receipt has to show (§19).
     *
     * find() gives the bare row and is what the refund rules read. This is the
     * one somebody looks at when they say "I paid this" — it carries the
     * invoice it settled, the patient it was for, who took it, and how much of
     * it has since been refunded.
     *
     * @return array<string,mixed>|null
     */
    public function findWithContext(int $id): ?array
    {
        return $this->query(
            'SELECT pay.*,
                    i.invoice_no, i.grand_total, i.paid_total, i.status AS invoice_status,
                    CONCAT(pt.first_name, \' \', pt.last_name) AS patient_name, pt.mrn,
                    u.name AS received_by_name,
                    COALESCE((SELECT SUM(r.amount) FROM refunds r
                               WHERE r.payment_id = pay.id AND r.status = \'completed\'), 0)
                        AS refunded_total
               FROM payments pay
               JOIN invoices i  ON i.id = pay.invoice_id
               JOIN patients pt ON pt.id = pay.patient_id
               LEFT JOIN users u ON u.id = pay.received_by
              WHERE pay.organization_id = :org AND pay.id = :id',
            ['org' => $this->scopeBinding(), 'id' => $id],
        )[0] ?? null;
    }

    /**
     * What is left of a payment once completed refunds are taken off it.
     *
     * Returns null when the payment does not exist.
     */
    public function remainingAfterRefunds(int $paymentId): ?string
    {
        $row = $this->query(
            'SELECT p.amount - COALESCE((
                        SELECT SUM(r.amount) FROM refunds r
                         WHERE r.payment_id = p.id AND r.status = \'completed\'
                    ), 0) AS remaining
               FROM payments p
              WHERE p.organization_id = :org AND p.id = :pid',
            ['org' => $this->scopeBinding(), 'pid' => $paymentId],
        )[0] ?? null;

        return $row === null ? null : (string) $row['remaining'];
    }

    /** Mark a payment refunded — only once nothing of it remains. */
    public function markRefunded(int $paymentId): void
    {
        \App\Core\Database::statement(
            'UPDATE payments SET status = \'refunded\', updated_at = :now
              WHERE organization_id = :org AND id = :pid',
            ['now' => now(), 'org' => $this->scopeBinding(), 'pid' => $paymentId],
        );
    }

    /**
     * The cashier's ledger view, with the invoice and patient each row is for.
     *
     * Capped at 300: this is a screen, and a clinic with more payments than
     * that in one filter wants a report, which is ReportService's job.
     *
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function ledger(array $filters): array
    {
        $where    = ['pay.organization_id = :org'];
        $bindings = ['org' => $this->scopeBinding()];

        foreach ([
            'patient_id' => 'pay.patient_id',
            'invoice_id' => 'pay.invoice_id',
            'method'     => 'pay.method',
            'status'     => 'pay.status',
        ] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[]        = "$column = :$key";
                $bindings[$key] = $filters[$key];
            }
        }
        if (!empty($filters['from'])) {
            $where[]          = 'pay.created_at >= :from';
            $bindings['from'] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $where[]        = 'pay.created_at <= :to';
            $bindings['to'] = $filters['to'] . ' 23:59:59';
        }

        return $this->query(
            'SELECT pay.*,
                    i.invoice_no, i.grand_total, i.status AS invoice_status,
                    CONCAT(pt.first_name, \' \', pt.last_name) AS patient_name, pt.mrn,
                    u.name AS received_by_name
               FROM payments pay
               JOIN invoices i  ON i.id = pay.invoice_id
               JOIN patients pt ON pt.id = pay.patient_id
               LEFT JOIN users u ON u.id = pay.received_by
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY pay.created_at DESC
              LIMIT 300',
            $bindings,
        );
    }
}
