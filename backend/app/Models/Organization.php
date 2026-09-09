<?php
declare(strict_types=1);

namespace App\Models;

/**
 * A clinic — the tenant root itself (§10).
 *
 * Not tenant scoped, because this IS the tenant: the question "which
 * organization" is answered by TenantMiddleware before any scoped model is
 * touched, and cannot be answered by scoping the organizations table itself.
 */
final class Organization extends Model
{
    public static function table(): string
    {
        return 'organizations';
    }

    public static function tenantScoped(): bool
    {
        return false;
    }

    /** @return list<string> */
    public static function fillable(): array
    {
        return [
            'name', 'slug', 'country_id', 'email', 'phone', 'address', 'city',
            'logo_path', 'currency_code', 'timezone', 'tax_rate', 'invoice_prefix',
            'next_invoice_no', 'status', 'created_at', 'updated_at',
        ];
    }

}
