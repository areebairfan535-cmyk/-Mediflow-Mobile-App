<?php
declare(strict_types=1);

namespace App\Models;

/** A subscription tier (§22). Platform-wide, not one clinic's copy. */
final class Plan extends Model
{
    public static function table(): string
    {
        return 'plans';
    }

    public static function tenantScoped(): bool
    {
        return false;
    }

    /** @return list<string> */
    public static function fillable(): array
    {
        return [
            'slug', 'name', 'description', 'price_monthly', 'price_yearly', 'currency_code',
            'max_doctors', 'max_staff', 'max_patients', 'max_storage_mb',
            'max_invoices_month', 'max_appointments_month', 'max_ai_calls_month',
            'features', 'is_active', 'sort_order', 'created_at', 'updated_at',
        ];
    }

}
