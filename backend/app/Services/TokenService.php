<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\TokenRepository;

/**
 * Access + refresh token lifecycle (§11).
 *
 * Design notes
 * ------------
 * - Tokens are 64 hex chars from random_bytes(32) — opaque, not JWTs. Opaque
 *   tokens can be revoked server-side the instant a device is removed or a
 *   password changes; a stateless JWT cannot.
 * - Only sha256(token) is stored. The plaintext exists once, in the response.
 * - Access tokens are short (30 min default) and carry the tenant they were
 *   issued for. Refresh tokens are long (30 days) and are the only thing
 *   POST /auth/refresh accepts.
 * - Refresh is ROTATING: spending a refresh token revokes it and its access
 *   tokens, then issues a fresh pair. Replaying an already-spent refresh
 *   token therefore fails, which is how token theft gets detected.
 */
final class TokenService
{
    private TokenRepository $tokens;

    public function __construct(?TokenRepository $tokens = null)
    {
        $this->tokens = $tokens ?? new TokenRepository();
    }

    /**
     * Mint a refresh token plus its first access token.
     *
     * @param array<string,mixed> $context device_name, device_id, ip, user_agent
     * @return array{
     *   access_token:string, refresh_token:string,
     *   token_type:string, expires_in:int, refresh_expires_in:int
     * }
     */
    public function issuePair(int $userId, ?int $organizationId, array $context = []): array
    {
        $config      = $GLOBALS['__config']['auth'];
        $accessTtl   = (int) $config['access_ttl_minutes'] * 60;
        $refreshTtl  = (int) $config['refresh_ttl_days'] * 86400;

        $refreshPlain = str_random(32);
        $refresh      = $this->tokens->create([
            'user_id'       => $userId,
            'type'          => 'refresh',
            'token_hash'    => $this->hash($refreshPlain),
            'active_org_id' => $organizationId,
            'device_name'   => $context['device_name'] ?? null,
            'device_id'     => $context['device_id']   ?? null,
            'ip_address'    => $context['ip']          ?? null,
            'user_agent'    => $context['user_agent']  ?? null,
            'expires_at'    => gmdate('Y-m-d H:i:s', time() + $refreshTtl),
            'created_at'    => now(),
        ]);

        $accessPlain = str_random(32);
        $this->tokens->create([
            'user_id'       => $userId,
            'type'          => 'access',
            'token_hash'    => $this->hash($accessPlain),
            'parent_id'     => (int) $refresh['id'],
            'active_org_id' => $organizationId,
            'device_id'     => $context['device_id'] ?? null,
            'ip_address'    => $context['ip']        ?? null,
            'user_agent'    => $context['user_agent'] ?? null,
            'expires_at'    => gmdate('Y-m-d H:i:s', time() + $accessTtl),
            'created_at'    => now(),
        ]);

        return [
            'access_token'       => $accessPlain,
            'refresh_token'      => $refreshPlain,
            'token_type'         => 'Bearer',
            'expires_in'         => $accessTtl,
            'refresh_expires_in' => $refreshTtl,
        ];
    }

    /**
     * Validate an access token. Returns [user, token] or null.
     *
     * @return array{0:array<string,mixed>, 1:array<string,mixed>}|null
     */
    public function resolveAccessToken(string $plaintext): ?array
    {
        $found = $this->tokens->findActiveWithUser($this->hash($plaintext), 'access');
        if ($found === null) {
            return null;
        }
        $this->tokens->touch((int) $found['token']['id']);
        return [$found['user'], $found['token']];
    }

    /**
     * Spend a refresh token and return a brand-new pair (rotation).
     *
     * @param array<string,mixed> $context
     * @return array{user: array<string,mixed>, tokens: array<string,mixed>}|null
     */
    public function rotate(string $refreshPlain, array $context = []): ?array
    {
        $found = $this->tokens->findActiveWithUser($this->hash($refreshPlain), 'refresh');
        if ($found === null) {
            return null;
        }

        $old  = $found['token'];
        $user = $found['user'];

        // Revoke the spent token and every access token minted from it, so a
        // stolen copy of either is dead the moment the legitimate client refreshes.
        $this->tokens->revokeFamily((int) $old['id']);

        $pair = $this->issuePair(
            (int) $user['id'],
            $old['active_org_id'] !== null ? (int) $old['active_org_id'] : null,
            $context + [
                'device_name' => $old['device_name'] ?? null,
                'device_id'   => $old['device_id']   ?? null,
            ],
        );

        return ['user' => $user, 'tokens' => $pair];
    }

    /** Revoke the access token used on this request, plus its refresh parent. */
    public function revokeSession(int $accessTokenId): void
    {
        $row = $this->tokens->find($accessTokenId);
        if ($row === null) {
            return;
        }
        if (($row['parent_id'] ?? null) !== null) {
            $this->tokens->revokeFamily((int) $row['parent_id']);
            return;
        }
        $this->tokens->revoke($accessTokenId);
    }

    public function revokeAll(int $userId): int
    {
        return $this->tokens->revokeAllForUser($userId);
    }

    /** @return list<array<string,mixed>> */
    public function sessions(int $userId): array
    {
        return $this->tokens->activeSessions($userId);
    }

    /**
     * Revoke one named session (device manager). Returns false when the
     * session does not belong to this user — never reveal that it exists.
     */
    public function revokeSessionForUser(int $userId, int $sessionId): bool
    {
        $row = $this->tokens->find($sessionId);
        if ($row === null || (int) $row['user_id'] !== $userId) {
            return false;
        }
        $this->tokens->revokeFamily($sessionId);
        return true;
    }

    /**
     * Re-point a live session at a different organization, after the caller
     * has verified membership. Keeps "switch clinic" from needing a re-login.
     */
    public function setActiveOrganization(int $accessTokenId, int $organizationId): void
    {
        \App\Core\Database::statement(
            'UPDATE auth_tokens
                SET active_org_id = :org
              WHERE id = :id OR id = (SELECT parent_id FROM (
                        SELECT parent_id FROM auth_tokens WHERE id = :id
                  ) AS p)',
            ['org' => $organizationId, 'id' => $accessTokenId],
        );
    }

    private function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}
