<?php
declare(strict_types=1);

namespace App\Models;

/**
 * A named set of permissions (§16).
 *
 * Not tenant scoped: system roles carry organization_id NULL and are shared by
 * every clinic, while a clinic's own custom role carries its id. Scoping the
 * table would hide the shared ones.
 */
final class Role extends Model
{
    public static function table(): string
    {
        return 'roles';
    }

    public static function tenantScoped(): bool
    {
        return false;
    }

    /** @return list<string> */
    public static function fillable(): array
    {
        return [
            'organization_id', 'slug', 'name', 'description', 'is_system',
            'created_at', 'updated_at',
        ];
    }

}
