<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Repository;
use App\Models\DeviceToken;

/**
 * Where each person's push notifications go (§20).
 */
final class DeviceTokenRepository extends Repository
{
    protected string $model = DeviceToken::class;

    /**
     * Register a device, or bring an existing registration back to life.
     *
     * The app calls this on every launch, so this runs far more often than a
     * device is actually new. Upserting on the token keeps that idempotent —
     * otherwise a phone opened daily for a month would collect thirty rows
     * and the patient would get thirty copies of every reminder.
     *
     * Re-registering also clears revoked_at: signing out revokes, signing
     * back in on the same phone should simply work.
     */
    public function register(int $userId, string $token, string $platform, ?string $deviceName): array
    {
        Database::statement(
            'INSERT INTO device_tokens
                (user_id, token, platform, device_name, last_seen_at, created_at, updated_at)
             VALUES (:uid, :token, :platform, :name, :now, :now, :now)
             ON DUPLICATE KEY UPDATE
                 user_id      = VALUES(user_id),
                 platform     = VALUES(platform),
                 device_name  = VALUES(device_name),
                 last_seen_at = VALUES(last_seen_at),
                 updated_at   = VALUES(updated_at),
                 revoked_at   = NULL,
                 last_error   = NULL',
            [
                'uid'      => $userId,
                'token'    => $token,
                'platform' => $platform,
                'name'     => $deviceName,
                'now'      => now(),
            ],
        );

        // Without the token. The app already has it — sending it back only
        // puts a delivery address into a response body and whatever logs that
        // response passes through.
        return $this->describeByToken($token) ?? [];
    }

    /** @return array<string,mixed>|null the row as the owner may see it */
    public function describeByToken(string $token): ?array
    {
        return Database::selectOne(
            'SELECT id, user_id, platform, device_name, last_seen_at,
                    revoked_at, last_error, created_at, updated_at
               FROM device_tokens WHERE token = :token',
            ['token' => $token],
        );
    }

    /**
     * The live tokens for one person.
     *
     * A doctor with a phone and a tablet has two, and both should buzz.
     *
     * @return list<string>
     */
    public function liveTokensFor(int $userId): array
    {
        $rows = Database::select(
            'SELECT token FROM device_tokens
              WHERE user_id = :uid AND revoked_at IS NULL
              ORDER BY last_seen_at DESC',
            ['uid' => $userId],
        );

        return array_map(static fn (array $r): string => (string) $r['token'], $rows);
    }

    /** @return list<array<string,mixed>> */
    public function listFor(int $userId): array
    {
        return Database::select(
            'SELECT id, platform, device_name, last_seen_at, revoked_at, last_error, created_at
               FROM device_tokens
              WHERE user_id = :uid
              ORDER BY revoked_at IS NOT NULL, last_seen_at DESC',
            ['uid' => $userId],
        );
    }

    /**
     * Stop pushing to a device.
     *
     * Kept rather than deleted, with the reason, so "why did this phone stop
     * buzzing" has an answer beyond a shrug.
     */
    public function revoke(string $token, ?string $reason = null): void
    {
        Database::statement(
            'UPDATE device_tokens
                SET revoked_at = :now, last_error = :reason, updated_at = :now
              WHERE token = :token AND revoked_at IS NULL',
            ['now' => now(), 'reason' => $reason, 'token' => $token],
        );
    }

    /**
     * Silence one device, by id, but only if it belongs to this person.
     *
     * By id and not by token because the token is hidden from find() — it is
     * a delivery address and does not need to travel back out to be revoked.
     * The user_id in the WHERE is what stops one account silencing another's
     * phone; a device id from somebody else simply matches nothing.
     *
     * @return bool whether anything was actually revoked
     */
    public function revokeOwned(int $id, int $userId, ?string $reason = null): bool
    {
        return Database::statement(
            'UPDATE device_tokens
                SET revoked_at = :now, last_error = :reason, updated_at = :now
              WHERE id = :id AND user_id = :uid AND revoked_at IS NULL',
            ['now' => now(), 'reason' => $reason, 'id' => $id, 'uid' => $userId],
        ) > 0;
    }

    /** Every device belonging to one person — used when they sign out of all. */
    public function revokeAllFor(int $userId, ?string $reason = null): int
    {
        return Database::statement(
            'UPDATE device_tokens
                SET revoked_at = :now, last_error = :reason, updated_at = :now
              WHERE user_id = :uid AND revoked_at IS NULL',
            ['now' => now(), 'reason' => $reason, 'uid' => $userId],
        );
    }
}
