<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Repository;

/**
 * auth_tokens. Stores only SHA-256 hashes: a database dump must not hand the
 * attacker usable session tokens.
 */
final class TokenRepository extends Repository
{
    protected string $table        = 'auth_tokens';
    protected bool   $tenantScoped = false;
    protected bool   $timestamps   = false;

    protected array $fillable = [
        'user_id', 'type', 'token_hash', 'parent_id', 'active_org_id',
        'device_name', 'device_id', 'ip_address', 'user_agent',
        'expires_at', 'revoked_at', 'last_used_at', 'created_at',
    ];

    protected array $hidden = ['token_hash'];

    /**
     * Look up a live token of the given type and return it with its user.
     * A single query keeps the hot auth path to one round trip.
     *
     * @return array{token: array<string,mixed>, user: array<string,mixed>}|null
     */
    public function findActiveWithUser(string $tokenHash, string $type): ?array
    {
        $row = Database::selectOne(
            'SELECT t.id            AS t_id,
                    t.user_id       AS t_user_id,
                    t.type          AS t_type,
                    t.parent_id     AS t_parent_id,
                    t.active_org_id AS t_active_org_id,
                    t.expires_at    AS t_expires_at,
                    u.*
               FROM auth_tokens t
               JOIN users u ON u.id = t.user_id
              WHERE t.token_hash = :hash
                AND t.type       = :type
                AND t.revoked_at IS NULL
                AND t.expires_at > UTC_TIMESTAMP()
              LIMIT 1',
            ['hash' => $tokenHash, 'type' => $type],
        );

        if ($row === null) {
            return null;
        }

        $token = [
            'id'            => (int) $row['t_id'],
            'user_id'       => (int) $row['t_user_id'],
            'type'          => $row['t_type'],
            'parent_id'     => $row['t_parent_id'] === null ? null : (int) $row['t_parent_id'],
            'active_org_id' => $row['t_active_org_id'] === null ? null : (int) $row['t_active_org_id'],
            'expires_at'    => $row['t_expires_at'],
        ];

        // Strip the joined token aliases, and the user columns that must never
        // reach a client. `SELECT u.*` is used for one round trip, so the
        // hiding UserRepository would normally do has to be repeated here:
        // failed_logins and locked_until are internal lockout state and tell an
        // attacker how close they are to the threshold.
        foreach ([
            't_id', 't_user_id', 't_type', 't_parent_id', 't_active_org_id', 't_expires_at',
            'password', 'failed_logins', 'locked_until', 'remember_token',
        ] as $k) {
            unset($row[$k]);
        }

        return ['token' => $token, 'user' => $row];
    }

    public function touch(int $tokenId): void
    {
        Database::statement(
            'UPDATE auth_tokens SET last_used_at = :now WHERE id = :id',
            ['now' => now(), 'id' => $tokenId],
        );
    }

    public function revoke(int $tokenId): void
    {
        Database::statement(
            'UPDATE auth_tokens SET revoked_at = :now WHERE id = :id AND revoked_at IS NULL',
            ['now' => now(), 'id' => $tokenId],
        );
    }

    /** Revoke a refresh token together with every access token minted from it. */
    public function revokeFamily(int $refreshTokenId): void
    {
        Database::statement(
            'UPDATE auth_tokens
                SET revoked_at = :now
              WHERE (id = :id OR parent_id = :id)
                AND revoked_at IS NULL',
            ['now' => now(), 'id' => $refreshTokenId],
        );
    }

    /** Log out every device (password change, "sign out everywhere"). */
    public function revokeAllForUser(int $userId): int
    {
        return Database::statement(
            'UPDATE auth_tokens
                SET revoked_at = :now
              WHERE user_id = :uid AND revoked_at IS NULL',
            ['now' => now(), 'uid' => $userId],
        );
    }

    /**
     * Active sessions for the device manager (§11).
     *
     * @return list<array<string,mixed>>
     */
    public function activeSessions(int $userId): array
    {
        return Database::select(
            'SELECT id, device_name, device_id, ip_address, user_agent,
                    active_org_id, created_at, last_used_at, expires_at
               FROM auth_tokens
              WHERE user_id   = :uid
                AND type      = \'refresh\'
                AND revoked_at IS NULL
                AND expires_at > UTC_TIMESTAMP()
              ORDER BY last_used_at DESC, created_at DESC',
            ['uid' => $userId],
        );
    }

    /** Housekeeping: drop rows that expired or were revoked long ago. */
    public function pruneExpired(int $olderThanDays = 30): int
    {
        return Database::statement(
            'DELETE FROM auth_tokens
              WHERE (expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :days DAY))
                 OR (revoked_at IS NOT NULL
                     AND revoked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :days DAY))',
            ['days' => $olderThanDays],
        );
    }
}
