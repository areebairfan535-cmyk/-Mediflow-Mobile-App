<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ConflictException;
use App\Core\NotFoundException;
use App\Core\Service;
use App\Core\ValidationException;
use App\Repositories\InvoiceRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\RefundRepository;
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

    private function payments(): PaymentRepository
    {
        return (new PaymentRepository())->forOrganization($this->requireOrganization());
    }

    private function refunds(): RefundRepository
    {
        return (new RefundRepository())->forOrganization($this->requireOrganization());
    }

    /**
     * Record a payment against an invoice.
     *
     * @param array<string,mixed> $data amount, method, gateway_ref?, notes?, paid_at?
     * @return array{payment: array<string,mixed>, invoice: array<string,mixed>}
     */
    public function record(int $invoiceId, array $data): array
    {
        $this->requireOrganization();

        return $this->transaction(function () use ($invoiceId, $data): array {
            // Lock the invoice for the whole check-then-write. Without this,
            // two cashiers taking the last payment at the same moment could
            // both pass the balance check and overpay the invoice.
            $invoice = $this->invoices()->findForUpdate($invoiceId);

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

            $payments  = $this->payments();
            $receiptNo = $payments->nextReceiptNo();

            $payment = $payments->create([
                'invoice_id'    => $invoiceId,
                'patient_id'    => (int) $invoice['patient_id'],
                'receipt_no'    => $receiptNo,
                'method'        => $data['method'] ?? 'cash',
                // Cash and adjustments settle immediately; a gateway payment
                // is only 'succeeded' once the gateway says so.
                'status'        => $data['status'] ?? 'succeeded',
                'currency_code' => $invoice['currency_code'],
                'amount'        => $amount,
                'gateway'       => $data['gateway']     ?? null,
                'gateway_ref'   => $data['gateway_ref'] ?? null,
                'paid_at'       => $data['paid_at']     ?? now(),
                'received_by'   => $this->actorId,
                'notes'         => $data['notes'] ?? null,
            ]);

            // Rebuild paid_total and the derived status from the ledger.
            $updated = $this->invoices()->recalculatePayments($invoiceId);

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
        $this->requireOrganization();

        $payment = $this->payments()->find($paymentId);

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
        $alreadyRefunded = $this->refunds()->claimedAgainstPayment($paymentId);

        $refundable = Money::subtract((string) $payment['amount'], $alreadyRefunded);

        if (Money::greaterThan($amount, $refundable)) {
            throw new ConflictException(sprintf(
                'Only %s %s of this payment can still be refunded.',
                $payment['currency_code'],
                Money::round($refundable),
            ));
        }

        return $this->refunds()->create([
            'payment_id'    => $paymentId,
            'invoice_id'    => (int) $payment['invoice_id'],
            'amount'        => $amount,
            'currency_code' => $payment['currency_code'],
            'reason'        => (string) $data['reason'],
            'status'        => 'pending',
            'created_by'    => $this->actorId,
        ]);
    }

    /**
     * Approve and complete a refund. Requires refund.approve, which the
     * requesting roles (billing_staff) deliberately do not hold.
     *
     * @return array{refund: array<string,mixed>, invoice: array<string,mixed>}
     */
    public function approveRefund(int $refundId): array
    {
        $this->requireOrganization();

        return $this->transaction(function () use ($refundId): array {
            $refunds = $this->refunds();
            $refund  = $refunds->findForUpdate($refundId);

            if ($refund === null) {
                throw new NotFoundException('Refund not found');
            }
            if ($refund['status'] !== 'pending') {
                throw new ConflictException("This refund is already {$refund['status']}.");
            }

            $refunds->complete($refundId, $this->actorId);

            // Mark the payment refunded only when nothing of it remains.
            $payments  = $this->payments();
            $paymentId = (int) $refund['payment_id'];
            $remaining = $payments->remainingAfterRefunds($paymentId);

            if ($remaining !== null && Money::isZero($remaining)) {
                $payments->markRefunded($paymentId);
            }

            return [
                'refund'  => $refunds->find($refundId) ?? [],
                'invoice' => $this->invoices()->recalculatePayments((int) $refund['invoice_id']),
            ];
        });
    }

    public function rejectRefund(int $refundId, ?string $reason): array
    {
        $this->requireOrganization();

        $refunds = $this->refunds();
        $refund  = $refunds->find($refundId);

        if ($refund === null) {
            throw new NotFoundException('Refund not found');
        }
        if ($refund['status'] !== 'pending') {
            throw new ConflictException("This refund is already {$refund['status']}.");
        }

        $refunds->reject($refundId, $this->actorId, $reason);

        return $refunds->find($refundId) ?? [];
    }

    /** @return list<array<string,mixed>> */
    public function ledger(array $filters): array
    {
        return $this->payments()->ledger($filters);
    }

    /**
     * One payment — the receipt (§19).
     *
     * @return array<string,mixed>
     */
    public function show(int $paymentId): array
    {
        $payment = $this->payments()->findWithContext($paymentId);

        if ($payment === null) {
            throw new NotFoundException('Payment not found');
        }

        return $payment;
    }

    /** @return list<array<string,mixed>> */
    public function pendingRefunds(): array
    {
        return $this->refunds()->pendingWithContext();
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
