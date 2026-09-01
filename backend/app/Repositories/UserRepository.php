<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Repository;

/**
 * Users are global identities, not tenant rows — the same person may work at
 * two clinics. Tenant scoping therefore lives in organization_users, and this
 * repository is explicitly NOT tenant-scoped.
 */
final class UserRepository extends Repository
{
    protected string $table        = 'users';
    protected bool   $tenantScoped = false;

    protected array $fillable = [
        'name', 'email', 'phone', 'password', 'avatar_path', 'locale',
        'is_platform_admin', 'status', 'email_verified_at', 'last_login_at',
        'failed_logins', 'locked_until', 'created_at', 'updated_at',
    ];

    /** Never leak the hash or lockout counters to a client. */
    protected array $hidden = ['password', 'failed_logins', 'locked_until'];

    /**
     * Look up by email INCLUDING the password hash — login needs it, so this
     * bypasses $hidden. Only AuthService may call it.
     *
     * @return array<string,mixed>|null
     */
    public function findForAuthentication(string $email): ?array
    {
        return \App\Core\Database::selectOne(
            'SELECT * FROM users WHERE email = :email LIMIT 1',
            ['email' => strtolower(trim($email))],
        );
    }

    public function emailExists(string $email): bool
    {
        return $this->exists(['email' => strtolower(trim($email))]);
    }

    public static function hashPassword(string $plain): string
    {
        // §17 "secure password hashing". Default algo so PHP upgrades follow.
        return password_hash($plain, PASSWORD_DEFAULT);
    }

    public static function verifyPassword(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    public function recordSuccessfulLogin(int $userId): void
    {
        \App\Core\Database::statement(
            'UPDATE users
                SET last_login_at = :now, failed_logins = 0, locked_until = NULL,
                    updated_at = :now
              WHERE id = :id',
            ['now' => now(), 'id' => $userId],
        );
    }

    /**
     * Increment the failure counter and lock the account once the threshold
     * is reached. Done in one statement so parallel attempts cannot race
     * past the limit.
     */
    public function recordFailedLogin(int $userId, int $maxAttempts, int $lockMinutes): void
    {
        \App\Core\Database::statement(
            'UPDATE users
                SET failed_logins = failed_logins + 1,
                    locked_until  = IF(failed_logins + 1 >= :max,
                                       DATE_ADD(UTC_TIMESTAMP(), INTERVAL :mins MINUTE),
                                       locked_until),
                    updated_at    = :now
              WHERE id = :id',
            ['max' => $maxAttempts, 'mins' => $lockMinutes, 'now' => now(), 'id' => $userId],
        );
    }

    /** @param array<string,mixed> $user */
    public static function isLocked(array $user): bool
    {
        $until = $user['locked_until'] ?? null;
        return $until !== null && strtotime((string) $until . ' UTC') > time();
    }
}
