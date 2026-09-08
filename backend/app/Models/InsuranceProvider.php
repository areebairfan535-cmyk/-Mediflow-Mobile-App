<?php
declare(strict_types=1);

namespace App\Models;

/**
 * An insurer (§15).
 *
 * Not tenant scoped because a provider can be shared across clinics in a
 * market; organization_id is set only on one a clinic added for itself.
 */
final class InsuranceProvider extends Model
{
    public static function table(): string
    {
        return 'insurance_providers';
    }

    public static function tenantScoped(): bool
    {
        return false;
    }

    /** @return list<string> */
    public static function fillable(): array
    {
        return [
            'organization_id', 'country_id', 'name', 'code', 'contact_email',
            'contact_phone', 'portal_url', 'claim_format', 'avg_settle_days',
            'is_active', 'created_at', 'updated_at',
        ];
    }

}
