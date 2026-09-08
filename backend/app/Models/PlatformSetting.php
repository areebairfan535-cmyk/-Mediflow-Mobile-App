<?php
declare(strict_types=1);

namespace App\Models;

/**
 * One deployment-wide switch (§21).
 *
 * Keyed by name rather than by an auto id, because a setting is identified by
 * what it is called. Deliberately holds no secrets: which payment gateway to
 * use is a setting, and the key that proves the account is yours is not — that
 * stays in the environment.
 */
final class PlatformSetting extends Model
{
    public static function table(): string
    {
        return 'platform_settings';
    }

    public static function primaryKey(): string
    {
        return 'setting_key';
    }

    public static function tenantScoped(): bool
    {
        return false;
    }

    /** No created_at on this table — a setting is written and rewritten. */
    public static function timestamps(): bool
    {
        return false;
    }

    /** @return list<string> */
    public static function fillable(): array
    {
        return ['setting_key', 'setting_value', 'updated_by', 'updated_at'];
    }
}
