<?php
declare(strict_types=1);

namespace App\Models;

/**
 * An access or refresh token (§16).
 *
 * Only the SHA-256 hash is stored, so a database dump hands the attacker
 * nothing usable. No updated_at: a token is issued, used and revoked, and each
 * of those has its own column.
 */
final class AuthToken extends Model
{
    public static function table(): string
    {
        return 'auth_tokens';
    }

    public static function tenantScoped(): bool
    {
        return false;
    }

    public static function timestamps(): bool
    {
        return false;
    }

    /** @return list<string> */
    public static function fillable(): array
    {
        return [
            'user_id', 'type', 'token_hash', 'parent_id', 'active_org_id',
            'device_name', 'device_id', 'ip_address', 'user_agent',
            'expires_at', 'revoked_at', 'last_used_at', 'created_at',
        ];
    }

    /** @return list<string> */
    public static function hidden(): array
    {
        return ['token_hash'];
    }
}
