<?php
declare(strict_types=1);

namespace App\Models;

/**
 * Money going back (§7).
 *
 * A refund is requested and approved by different people on purpose: the
 * roles that may ask (billing staff) deliberately do not hold refund.approve.
 * That is why this is its own record with its own status rather than a
 * negative payment.
 */
final class Refund extends Model
{
    public static function table(): string
    {
        return 'refunds';
    }

    /** @return list<string> */
    public static function fillable(): array
    {
        return [
            'payment_id', 'invoice_id', 'amount', 'currency_code', 'reason',
            'status', 'gateway_ref', 'approved_by', 'refunded_at',
            'created_by', 'created_at', 'updated_at',
        ];
    }
}
