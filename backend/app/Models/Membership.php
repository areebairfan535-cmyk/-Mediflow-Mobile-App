<?php
declare(strict_types=1);

namespace App\Models;

/**
 * A person's place in a clinic (§16) — the row that joins a user to an
 * organization and says which role they hold there.
 *
 * This is why User is not tenant scoped: one account, one password, one
 * identity, and a separate membership row for each clinic that person works
 * in. Revoking access to one clinic deletes a membership, never an account.
 *
 * Not tenant scoped itself, because the organization id is part of the key
 * being looked up rather than a filter applied to it.
 */
final class Membership extends Model
{
    public static function table(): string
    {
        return 'organization_users';
    }

    public static function tenantScoped(): bool
    {
        return false;
    }

    /** @return list<string> */
    public static function fillable(): array
    {
        return [
            'organization_id', 'user_id', 'role_id', 'job_title', 'status',
            'invited_at', 'joined_at', 'created_at', 'updated_at',
        ];
    }
}
