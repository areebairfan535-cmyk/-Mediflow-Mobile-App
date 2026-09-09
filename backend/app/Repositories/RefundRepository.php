<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Repository;
use App\Models\Refund;

/**
 * Refunds (§7). Moved out of PaymentService per §18.
 */
final class RefundRepository extends Repository
{
    protected string $model = Refund::class;

    /**
     * Read a refund and hold it for the rest of the transaction.
     *
     * Approval reads the status, decides, then writes. Without the lock two
     * approvers clicking at once could both see 'pending' and both complete
     * it, refunding the money twice.
     *
     * @return array<string,mixed>|null
     */
    public function findForUpdate(int $id): ?array
    {
        return $this->query(
            'SELECT * FROM refunds WHERE organization_id = :org AND id = :id FOR UPDATE',
            ['org' => $this->scopeBinding(), 'id' => $id],
        )[0] ?? null;
    }

    /**
     * How much of one payment is already spoken for.
     *
     * Pending and approved rows count, not just completed ones — otherwise two
     * requests could each be checked against the full amount and both pass.
     */
    public function claimedAgainstPayment(int $paymentId): string
    {
        $row = $this->query(
            'SELECT COALESCE(SUM(amount), 0) AS total
               FROM refunds
              WHERE organization_id = :org AND payment_id = :pid
                AND status IN (\'pending\', \'approved\', \'completed\')',
            ['org' => $this->scopeBinding(), 'pid' => $paymentId],
        )[0] ?? [];

        return (string) ($row['total'] ?? '0');
    }

    public function complete(int $id, ?int $approvedBy): void
    {
        Database::statement(
            'UPDATE refunds
                SET status = \'completed\', approved_by = :by, refunded_at = :now,
                    updated_at = :now
              WHERE organization_id = :org AND id = :id',
            ['by' => $approvedBy, 'now' => now(), 'org' => $this->scopeBinding(), 'id' => $id],
        );
    }

    /**
     * Reject, keeping why on the record.
     *
     * The reason is appended rather than replacing the requester's, so the
     * row still says what was asked for as well as what was decided.
     */
    public function reject(int $id, ?int $approvedBy, ?string $reason): void
    {
        Database::statement(
            'UPDATE refunds
                SET status = \'rejected\', approved_by = :by,
                    reason = CONCAT(reason, :suffix), updated_at = :now
              WHERE organization_id = :org AND id = :id',
            [
                'by'     => $approvedBy,
                'suffix' => $reason !== null ? " | Rejected: $reason" : ' | Rejected',
                'now'    => now(),
                'org'    => $this->scopeBinding(),
                'id'     => $id,
            ],
        );
    }

    /**
     * The approval queue, with enough context to decide without opening each one.
     *
     * @return list<array<string,mixed>>
     */
    public function pendingWithContext(): array
    {
        return $this->query(
            'SELECT r.*, i.invoice_no,
                    CONCAT(p.first_name, \' \', p.last_name) AS patient_name,
                    pay.receipt_no, pay.method,
                    u.name AS requested_by_name
               FROM refunds r
               JOIN invoices i ON i.id = r.invoice_id
               JOIN patients p ON p.id = i.patient_id
               JOIN payments pay ON pay.id = r.payment_id
               LEFT JOIN users u ON u.id = r.created_by
              WHERE r.organization_id = :org AND r.status = \'pending\'
              ORDER BY r.created_at',
            ['org' => $this->scopeBinding()],
        );
    }
}
