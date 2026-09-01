<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Repository;

/**
 * password_resets. Like auth_tokens, it holds hashes only — the six digits
 * themselves exist in the email and nowhere else.
 *
 * Not tenant-scoped: forgetting a password is something a person does, and the
 * person may belong to no clinic at all (a fresh account waiting to be linked).
 */
final class PasswordResetRepository extends Repository
{
    protected string $table        = 'password_resets';
    protected bool   $tenantScoped = false;

    protected array $fillable = [
        'user_id', 'code_hash', 'expires_at', 'used_at', 'attempts',
        'ip_address', 'created_at', 'updated_at',
    ];

    protected array $hidden = ['code_hash'];

    /**
     * The live request for this user, if there is one.
     *
     * Newest first: asking again re-sends a code, and it is the last one to
     * arrive that the person will be looking at.
     *
     * @return array<string,mixed>|null
     */
    public function liveFor(int $userId): ?array
    {
        return Database::selectOne(
            'SELECT * FROM password_resets
              WHERE user_id = :user
                AND used_at IS NULL
                AND expires_at > :now
           ORDER BY id DESC
              LIMIT 1',
            ['user' => $userId, 'now' => now()],
        );
    }

    /**
     * Retire everything still open for this user. Called before issuing a new
     * code so that only one can ever work, and again after a successful reset.
     */
    public function closeOpen(int $userId): int
    {
        return Database::statement(
            'UPDATE password_resets
                SET used_at = :now, updated_at = :now
              WHERE user_id = :user AND used_at IS NULL',
            ['user' => $userId, 'now' => now()],
        );
    }

    public function recordAttempt(int $id): void
    {
        Database::statement(
            'UPDATE password_resets
                SET attempts = attempts + 1, updated_at = :now
              WHERE id = :id',
            ['id' => $id, 'now' => now()],
        );
    }

    /** Housekeeping: expired rows are noise, not history. */
    public function purgeExpired(): int
    {
        return Database::statement(
            'DELETE FROM password_resets WHERE expires_at < :cutoff',
            ['cutoff' => date('Y-m-d H:i:s', strtotime('-7 days'))],
        );
    }
}
