<?php
declare(strict_types=1);

namespace App\Models;

/** A bill (§6, §7). */
final class Invoice extends Model
{
    public static function table(): string
    {
        return 'invoices';
    }

    /** @return list<string> */
    public static function fillable(): array
    {
        return [
            'patient_id', 'encounter_id', 'invoice_no', 'status', 'currency_code',
            'subtotal', 'discount_total', 'tax_total', 'grand_total', 'paid_total',
            'patient_payable', 'insurance_payable', 'issue_date', 'due_date',
            'notes', 'pdf_path', 'issued_by', 'cancelled_reason',
            'created_by', 'updated_by', 'created_at', 'updated_at',
        ];
    }
}
