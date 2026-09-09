<?php
declare(strict_types=1);

namespace App\Models;

/**
 * One line of the payment ledger (§7).
 *
 * §6 keeps invoices and payments separate so an invoice can take many
 * payments — part-payment is the normal case in a clinic, not an edge case.
 * The invoice's paid_total is a cached SUM of these rows, rebuilt after each
 * write rather than incremented, so the header can never drift from the ledger.
 */
final class Payment extends Model
{
    public static function table(): string
    {
        return 'payments';
    }

    /** @return list<string> */
    public static function fillable(): array
    {
        return [
            'invoice_id', 'patient_id', 'receipt_no', 'method', 'status',
            'currency_code', 'amount', 'gateway', 'gateway_ref', 'gateway_payload',
            'paid_at', 'received_by', 'notes', 'created_at', 'updated_at',
        ];
    }
}
