<?php
declare(strict_types=1);

namespace App\Models;

/** A pending password-reset request (§16). */
final class PasswordReset extends Model
{
    public static function table(): string
    {
        return 'password_resets';
    }

    public static function tenantScoped(): bool
    {
        return false;
    }

    /** @return list<string> */
    public static function fillable(): array
    {
        return [
            'user_id', 'code_hash', 'expires_at', 'used_at', 'attempts',
            'ip_address', 'created_at', 'updated_at',
        ];
    }

    /** The code is stored hashed and must never be read back. @return list<string> */
    public static function hidden(): array
    {
        return ['code_hash'];
    }
}
