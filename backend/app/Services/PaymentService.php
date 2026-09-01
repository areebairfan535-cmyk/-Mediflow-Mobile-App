<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ConflictException;
use App\Core\Database;
use App\Core\NotFoundException;
use App\Core\Service;
use App\Core\ValidationException;
use App\Repositories\InvoiceRepository;
use App\Services\Billing\Money;

/**
 * Payments and refunds (§7).
 *
 * §6 requires invoices and payments to be separate entities so one invoice can
 * take many payments — part-payment is the normal case in a clinic, not an
 * edge case. Every payment is a row in a ledger; the invoice's `paid_total` is
 * a cached SUM of that ledger, rebuilt after each write rather than
 * incremented, so the header can never drift from the rows.
 */
final class PaymentService extends Service
{
    private function invoices(): InvoiceRepository
    {
        return (new InvoiceRepository())->forOrganization($this->requireOrganization());
    }

    /**
     * Record a payment against an invoice.
     *
     * @param array<string,mixed> $data amount, method, gateway_ref?, notes?, paid_at?
     * @return array{payment: array<string,mixed>, invoice: array<string,mixed>}
     */
    public function record(int $invoiceId, array $data): array
    {
        $org = $this->requireOrganization();

        return $this->transaction(function () use ($org, $invoiceId, $data): array {
            // Lock the invoice for the whole check-then-write. Without this,
            // two cashiers taking the last payment at the same moment could
            // both pass the balance check and overpay the invoice.
            $invoice = Database::selectOne(
                'SELECT * FROM invoices WHERE organization_id = :org AND id = :id FOR UPDATE',
                ['org' => $org, 'id' => $invoiceId],
            );

            if ($invoice === null) {
                throw new NotFoundException('Invoice not found');
            }

            $this->assertPayable($invoice);

            $amount = Money::round($data['amount']);

            if (Money::compare($amount, '0') <= 0) {
                throw new ValidationException(['amount' => ['Amount must be greater than zero.']]);
            }

            $balance = Money::round(
                Money::subtract((string) $invoice['grand_total'], (string) $invoice['paid_total']),
            );

            if (Money::greaterThan($amount, $balance)) {
                throw new ConflictException(sprintf(
                    'That is more than the %s %s outstanding on this invoice.',
                    $invoice['currency_code'],
                    $balance,
                ));
            }

            $receiptNo = $this->nextReceiptNo($org);

            Database::statement(
                'INSERT INTO payments
                    (organization_id, invoice_id, patient_id, receipt_no, method, status,
                     currency_code, amount, gateway, gateway_ref, paid_at, received_by,
                     notes, created_at, updated_at)
                 VALUES (:org, :iid, :pid, :receipt, :method, :status, :cur, :amount,
                         :gateway, :ref, :paid_at, :by, :notes, :now, :now)',
                [
                    'org'     => $org,
                    'iid'     => $invoiceId,
                    'pid'     => (int) $invoice['patient_id'],
                    'receipt' => $receiptNo,
                    'method'  => $data['method'] ?? 'cash',
                    // Cash and adjustments settle immediately; a gateway
                    // payment is only 'succeeded' once the gateway says so.
                    'status'  => $data['status'] ?? 'succeeded',
                    'cur'     => $invoice['currency_code'],
                    'amount'  => $amount,
                    'gateway' => $data['gateway']     ?? null,
                    'ref'     => $data['gateway_ref'] ?? null,
                    'paid_at' => $data['paid_at']     ?? now(),
                    'by'      => $this->actorId,
                    'notes'   => $data['notes'] ?? null,
                    'now'     => now(),
                ],
            );

            $paymentId = Database::lastInsertId();

            // Rebuild paid_total and the derived status from the ledger.
            $updated = $this->invoices()->recalculatePayments($invoiceId);

            $payment = Database::selectOne(
                'SELECT * FROM payments WHERE id = :id',
                ['id' => $paymentId],
            ) ?? [];

            // §20 "payment received". The receipt is what the patient wants to
            // see in the app, so it goes in the message.
            try {
                (new NotificationService($this->organizationId, $this->actorId))->notifyPatient(
                    (int) $invoice['patient_id'],
                    'payment.received',
                    [
                        'amount'       => $invoice['currency_code'] . ' ' . $amount,
                        'receipt_no'   => $receiptNo,
                        'subject_type' => 'invoice',
                        'subject_id'   => $invoiceId,
                    ],
                );
            } catch (\Throwable $e) {
                error_log('[notify] payment notification failed: ' . $e->getMessage());
            }

            return ['payment' => $payment, 'invoice' => $updated];
        });
    }

    /**
     * Request a refund. It is created pending — approving it is a separate
     * act, because giving money back is exactly the decision that should not
     * be one click by one person (§7 ledger, §11 policy authorisation).
     *
     * @param array<string,mixed> $data amount, reason
     */
    public function requestRefund(int $paymentId, array $data): array
    {
        $org = $this->requireOrganization();

        $payment = Database::selectOne(
            'SELECT * FROM payments WHERE organization_id = :org AND id = :id',
            ['org' => $org, 'id' => $paymentId],
        );

        if ($payment === null) {
            throw new NotFoundException('Payment not found');
        }
        if ($payment['status'] !== 'succeeded') {
            throw new ConflictException(
                "Only a succeeded payment can be refunded — this one is {$payment['status']}."
            );
        }

        $amount = Money::round($data['amount'] ?? $payment['amount']);

        if (Money::compare($amount, '0') <= 0) {
            throw new ValidationException(['amount' => ['Refund must be greater than zero.']]);
        }

        // Cannot refund more than this payment, net of refunds already against it.
        $alreadyRefunded = (string) (Database::selectOne(
            'SELECT COALESCE(SUM(amount), 0) AS total
               FROM refunds
              WHERE organization_id = :org AND payment_id = :pid
                AND status IN (\'pending\', \'approved\', \'completed\')',
            ['org' => $org, 'pid' => $paymentId],
        )['total'] ?? '0');

        $refundable = Money::subtract((string) $payment['amount'], $alreadyRefunded);

        if (Money::greaterThan($amount, $refundable)) {
            throw new ConflictException(sprintf(
                'Only %s %s of this payment can still be refunded.',
                $payment['currency_code'],
                Money::round($refundable),
            ));
        }

        Database::statement(
            'INSERT INTO refunds
                (organization_id, payment_id, invoice_id, amount, currency_code,
                 reason, status, created_by, created_at, updated_at)
             VALUES (:org, :pid, :iid, :amount, :cur, :reason, \'pending\', :by, :now, :now)',
            [
                'org'    => $org,
                'pid'    => $paymentId,
                'iid'    => (int) $payment['invoice_id'],
                'amount' => $amount,
                'cur'    => $payment['currency_code'],
                'reason' => (string) $data['reason'],
                'by'     => $this->actorId,
                'now'    => now(),
            ],
        );

        return Database::selectOne(
            'SELECT * FROM refunds WHERE id = :id',
            ['id' => Database::lastInsertId()],
        ) ?? [];
    }

    /**
     * Approve and complete a refund. Requires refund.approve, which the
     * requesting roles (billing_staff) deliberately do not hold.
     *
     * @return array{refund: array<string,mixed>, invoice: array<string,mixed>}
     */
    public function approveRefund(int $refundId): array
    {
        $org = $this->requireOrganization();

        return $this->transaction(function () use ($org, $refundId): array {
            $refund = Database::selectOne(
                'SELECT * FROM refunds WHERE organization_id = :org AND id = :id FOR UPDATE',
                ['org' => $org, 'id' => $refundId],
            );

            if ($refund === null) {
                throw new NotFoundException('Refund not found');
            }
            if ($refund['status'] !== 'pending') {
                throw new ConflictException("This refund is already {$refund['status']}.");
            }

            Database::statement(
                'UPDATE refunds
                    SET status = \'completed\', approved_by = :by, refunded_at = :now,
                        updated_at = :now
                  WHERE organization_id = :org AND id = :id',
                ['by' => $this->actorId, 'now' => now(), 'org' => $org, 'id' => $refundId],
            );

            // Mark the payment refunded only when nothing of it remains.
            $remaining = Database::selectOne(
                'SELECT p.amount - COALESCE((
                            SELECT SUM(r.amount) FROM refunds r
                             WHERE r.payment_id = p.id AND r.status = \'completed\'
                        ), 0) AS remaining
                   FROM payments p WHERE p.id = :pid',
                ['pid' => (int) $refund['payment_id']],
            );

            if ($remaining !== null && Money::isZero((string) $remaining['remaining'])) {
                Database::statement(
                    'UPDATE payments SET status = \'refunded\', updated_at = :now WHERE id = :pid',
                    ['now' => now(), 'pid' => (int) $refund['payment_id']],
                );
            }

            $invoice = $this->invoices()->recalculatePayments((int) $refund['invoice_id']);

            return [
                'refund'  => Database::selectOne('SELECT * FROM refunds WHERE id = :id', ['id' => $refundId]) ?? [],
                'invoice' => $invoice,
            ];
        });
    }

    public function rejectRefund(int $refundId, ?string $reason): array
    {
        $org = $this->requireOrganization();

        $refund = Database::selectOne(
            'SELECT * FROM refunds WHERE organization_id = :org AND id = :id',
            ['org' => $org, 'id' => $refundId],
        );
        if ($refund === null) {
            throw new NotFoundException('Refund not found');
        }
        if ($refund['status'] !== 'pending') {
            throw new ConflictException("This refund is already {$refund['status']}.");
        }

        Database::statement(
            'UPDATE refunds
                SET status = \'rejected\', approved_by = :by,
                    reason = CONCAT(reason, :suffix), updated_at = :now
              WHERE organization_id = :org AND id = :id',
            [
                'by'     => $this->actorId,
                'suffix' => $reason !== null ? " | Rejected: $reason" : ' | Rejected',
                'now'    => now(),
                'org'    => $org,
                'id'     => $refundId,
            ],
        );

        return Database::selectOne('SELECT * FROM refunds WHERE id = :id', ['id' => $refundId]) ?? [];
    }

    /** @return list<array<string,mixed>> */
    public function ledger(array $filters): array
    {
        $where    = ['pay.organization_id = :org'];
        $bindings = ['org' => $this->requireOrganization()];

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

        return Database::select(
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

    /** @return list<array<string,mixed>> */
    public function pendingRefunds(): array
    {
        return Database::select(
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
            ['org' => $this->requireOrganization()],
        );
    }

    private function nextReceiptNo(int $org): string
    {
        $row = Database::selectOne(
            'SELECT COALESCE(MAX(CAST(SUBSTRING(receipt_no, 5) AS UNSIGNED)), 0) AS n
               FROM payments
              WHERE organization_id = :org AND receipt_no REGEXP \'^RCT-[0-9]+$\'',
            ['org' => $org],
        );
        return sprintf('RCT-%06d', ((int) ($row['n'] ?? 0)) + 1);
    }

    /** @param array<string,mixed> $invoice */
    private function assertPayable(array $invoice): void
    {
        if ($invoice['status'] === 'draft') {
            throw new ConflictException('Issue the invoice before taking payment against it.');
        }
        if (in_array($invoice['status'], ['cancelled', 'refunded'], true)) {
            throw new ConflictException(
                "A {$invoice['status']} invoice cannot take payment."
            );
        }
        if ($invoice['status'] === 'paid') {
            throw new ConflictException('This invoice is already fully paid.');
        }
    }
}
