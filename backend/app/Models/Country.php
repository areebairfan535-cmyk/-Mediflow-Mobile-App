<?php
declare(strict_types=1);

namespace App\Models;

/**
 * A market (§23).
 *
 * §23 forbids hard-coded country behaviour, so this row IS the configuration —
 * currency, timezone, date format, tax rate and invoice prefix all resolve
 * through it wherever a clinic has not overridden them.
 */
final class Country extends Model
{
    public static function table(): string
    {
        return 'countries';
    }

    public static function tenantScoped(): bool
    {
        return false;
    }

    /** @return list<string> */
    public static function fillable(): array
    {
        return [
            'code', 'name', 'currency_code', 'currency_symbol', 'timezone', 'date_format',
            'default_tax_rate', 'invoice_prefix', 'is_active', 'created_at', 'updated_at',
        ];
    }

}
