<?php
declare(strict_types=1);

namespace App\Models;

/**
 * A login (§16). One person, one account, however many clinics they work in —
 * membership is a separate row, which is why this is not tenant scoped.
 */
final class User extends Model
{
    public static function table(): string
    {
        return 'users';
    }

    public static function tenantScoped(): bool
    {
        return false;
    }

    /** @return list<string> */
    public static function fillable(): array
    {
        return [
            'name', 'email', 'phone', 'password', 'avatar_path', 'locale',
            'is_platform_admin', 'status', 'email_verified_at', 'last_login_at',
            'failed_logins', 'locked_until', 'created_at', 'updated_at',
        ];
    }

    /**
     * Never leaves this layer.
     *
     * The password hash for the obvious reason; the lockout counters because
     * they tell an attacker how close a guessing run is to tripping the lock.
     *
     * @return list<string>
     */
    public static function hidden(): array
    {
        return ['password', 'failed_logins', 'locked_until'];
    }
}
