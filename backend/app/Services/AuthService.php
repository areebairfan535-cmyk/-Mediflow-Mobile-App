<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ConflictException;
use App\Core\Database;
use App\Core\ForbiddenException;
use App\Core\Request;
use App\Core\UnauthorizedException;
use App\Core\ValidationException;
use App\Repositories\OrganizationRepository;
use App\Repositories\PasswordResetRepository;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;

/**
 * Registration, login, refresh, logout, password change (§11).
 *
 * All of the business rules that a controller must not contain live here:
 * lockout after repeated failures, which organizations a login may enter,
 * what a successful response looks like.
 */
final class AuthService
{
    /** How long a reset code lives. Long enough to find the email, no longer. */
    private const RESET_MINUTES = 30;

    /** Wrong guesses a single code tolerates before it is thrown away. */
    private const RESET_ATTEMPTS = 5;

    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
        private readonly OrganizationRepository $organizations = new OrganizationRepository(),
        private readonly RoleRepository $roles = new RoleRepository(),
        private readonly TokenService $tokens = new TokenService(),
        private readonly RbacService $rbac = new RbacService(),
        private readonly AuditService $audit = new AuditService(),
        private readonly PasswordResetRepository $resets = new PasswordResetRepository(),
    ) {
    }

    /**
     * Self-service registration. Creates the user only — a patient becomes
     * attached to a clinic when that clinic registers them, and clinic staff
     * are invited by an org owner. Registration therefore never grants
     * membership anywhere, which is what keeps tenancy safe by default.
     *
     * @param array<string,mixed> $data validated: name, email, password, phone?
     * @return array<string,mixed>
     */
    public function register(Request $request, array $data): array
    {
        $email = strtolower(trim((string) $data['email']));

        if ($this->users->emailExists($email)) {
            // Registration is public, so the message is deliberately the same
            // shape a caller would get for any conflict — but the plan needs a
            // usable API, so we do confirm the collision here rather than
            // silently succeeding. Rate limiting (throttle:auth) is what stops
            // this endpoint being used to enumerate accounts.
            throw new ConflictException('An account with this email already exists');
        }

        $this->assertPasswordStrength((string) $data['password']);

        $user = $this->users->create([
            'name'       => trim((string) $data['name']),
            'email'      => $email,
            'phone'      => isset($data['phone']) ? trim((string) $data['phone']) : null,
            'password'   => UserRepository::hashPassword((string) $data['password']),
            'locale'     => $data['locale'] ?? 'en',
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit->logAuth($request, 'register', (int) $user['id']);

        $tokens = $this->tokens->issuePair((int) $user['id'], null, $this->deviceContext($request));

        return [
            'user'          => $this->publicUser($user),
            'organizations' => [],
            'auth'          => $tokens,
        ];
    }

    /**
     * Email + password login.
     *
     * @param array<string,mixed> $data validated: email, password, organization_id?
     * @return array<string,mixed>
     */
    public function login(Request $request, array $data): array
    {
        $config = $GLOBALS['__config']['auth'];
        $email  = strtolower(trim((string) $data['email']));

        $user = $this->users->findForAuthentication($email);

        // Uniform failure for "no such user" and "wrong password": the response
        // must not tell an attacker which emails are registered.
        if ($user === null) {
            $this->audit->logAuth($request, 'login_failed', null, 'unknown email');
            throw new UnauthorizedException('Invalid email or password');
        }

        if (UserRepository::isLocked($user)) {
            $this->audit->logAuth($request, 'login_blocked', (int) $user['id'], 'account locked');
            throw new ForbiddenException(
                'Too many failed attempts. This account is temporarily locked.'
            );
        }

        if (!UserRepository::verifyPassword((string) $data['password'], (string) $user['password'])) {
            $this->users->recordFailedLogin(
                (int) $user['id'],
                (int) $config['max_failed_logins'],
                (int) $config['lockout_minutes'],
            );
            $this->audit->logAuth($request, 'login_failed', (int) $user['id'], 'bad password');
            throw new UnauthorizedException('Invalid email or password');
        }

        if ($user['status'] !== 'active') {
            $this->audit->logAuth($request, 'login_blocked', (int) $user['id'], 'account disabled');
            throw new ForbiddenException('This account has been disabled');
        }

        $this->users->recordSuccessfulLogin((int) $user['id']);

        $memberships = $this->rbac->membershipsFor((int) $user['id']);

        // Pick the tenant this session starts in.
        $activeOrgId = null;
        if (isset($data['organization_id'])) {
            $requested = (int) $data['organization_id'];
            $match     = array_filter(
                $memberships,
                static fn(array $m): bool => (int) $m['organization_id'] === $requested,
            );
            if ($match === []) {
                throw new ForbiddenException('You are not a member of that organization');
            }
            $activeOrgId = $requested;
        } elseif (count($memberships) === 1) {
            $activeOrgId = (int) $memberships[0]['organization_id'];
        }

        $tokens = $this->tokens->issuePair(
            (int) $user['id'],
            $activeOrgId,
            $this->deviceContext($request),
        );

        $this->audit->logAuth($request, 'login', (int) $user['id']);

        return [
            'user'            => $this->publicUser($user),
            'organizations'   => $memberships,
            'active_org_id'   => $activeOrgId,
            'auth'            => $tokens,
        ];
    }

    /**
     * Exchange a refresh token for a new pair (rotation).
     *
     * @return array<string,mixed>
     */
    public function refresh(Request $request, string $refreshToken): array
    {
        $result = $this->tokens->rotate($refreshToken, $this->deviceContext($request));

        if ($result === null) {
            // Either expired, revoked, or already spent. A spent-token replay
            // is a theft signal, but we cannot tell which case this is without
            // storing spent hashes, so treat all three the same.
            $this->audit->logAuth($request, 'refresh_failed', null, 'invalid refresh token');
            throw new UnauthorizedException('Invalid or expired refresh token');
        }

        $user = $result['user'];
        if (($user['status'] ?? 'active') !== 'active') {
            throw new ForbiddenException('This account has been disabled');
        }

        $this->audit->logAuth($request, 'refresh', (int) $user['id']);

        return [
            'user'          => $this->publicUser($user),
            'organizations' => $this->rbac->membershipsFor((int) $user['id']),
            'auth'          => $result['tokens'],
        ];
    }

    public function logout(Request $request): void
    {
        $tokenId = $request->tokenId();
        if ($tokenId !== null) {
            $this->tokens->revokeSession($tokenId);
        }
        $this->audit->logAuth($request, 'logout', $request->userId());
    }

    public function logoutEverywhere(Request $request): int
    {
        $userId = $request->userId();
        if ($userId === null) {
            return 0;
        }
        $count = $this->tokens->revokeAll($userId);
        $this->audit->logAuth($request, 'logout_all', $userId, "revoked $count tokens");
        return $count;
    }

    /**
     * Change password. Every other session is revoked, because a password
     * change is the standard response to suspecting compromise.
     */
    public function changePassword(Request $request, string $current, string $new): void
    {
        $userId = (int) $request->userId();
        $row    = $this->users->findForAuthentication((string) $request->user()['email']);

        if ($row === null
            || !UserRepository::verifyPassword($current, (string) $row['password'])
        ) {
            throw new UnauthorizedException('Current password is incorrect');
        }

        $this->assertPasswordStrength($new);

        if (UserRepository::verifyPassword($new, (string) $row['password'])) {
            throw new ValidationException(
                ['new_password' => ['The new password must be different from the current one.']]
            );
        }

        Database::transaction(function () use ($userId, $new): void {
            $this->users->update($userId, [
                'password'   => UserRepository::hashPassword($new),
                'updated_at' => now(),
            ]);
            $this->tokens->revokeAll($userId);
        });

        $this->audit->logAuth($request, 'password_changed', $userId);
    }

    /**
     * Step one of a forgotten password (§11): send a six-digit code.
     *
     * The response is deliberately identical whether or not the email is
     * registered. Login already refuses to say which addresses exist, and a
     * reset form that happily confirms it would undo that in one request.
     *
     * Only one code is ever live per person: asking again retires the last one,
     * so a stolen older message is worthless.
     *
     * @return array<string,mixed>
     */
    public function forgotPassword(Request $request, string $email): array
    {
        $email   = strtolower(trim($email));
        $minutes = self::RESET_MINUTES;
        $user    = $this->users->findForAuthentication($email);

        // Same words, same shape, same timing class — nothing here distinguishes
        // a real address from one that was never registered.
        $answer = [
            'message'            => 'If that email has an account, a reset code is on its way.',
            'expires_in_minutes' => $minutes,
        ];

        if ($user === null || $user['status'] !== 'active') {
            $this->audit->logAuth($request, 'password_reset_requested', null, 'no live account');
            return $answer;
        }

        $userId = (int) $user['id'];
        $code   = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Database::transaction(function () use ($userId, $code, $minutes, $request): void {
            $this->resets->closeOpen($userId);
            $this->resets->create([
                'user_id'    => $userId,
                'code_hash'  => $this->hashResetCode($userId, $code),
                'expires_at' => date('Y-m-d H:i:s', strtotime("+$minutes minutes")),
                'ip_address' => $request->ip(),
            ]);
        });

        $this->queueResetEmail($userId, (string) $user['email'], (string) $user['name'], $code);
        $this->audit->logAuth($request, 'password_reset_requested', $userId);

        // Outside production there is usually no SMTP server to carry the code,
        // and a reset flow nobody can finish is a reset flow nobody can test.
        // Production never reaches this line.
        if (($GLOBALS['__config']['app']['env'] ?? 'local') !== 'production') {
            $answer['code'] = $code;
            $answer['delivery'] = 'Returned here because APP_ENV is not production.';
        }

        return $answer;
    }

    /**
     * Step two: the code, and the password it unlocks.
     *
     * A wrong code is counted, and after a few the request is dead — six digits
     * are only safe because guessing them is not allowed to be cheap.
     *
     * Succeeding does three things beyond setting the password: it retires the
     * code, it signs every device out (the old password may be the reason the
     * account is in trouble), and it clears the lockout, because someone locked
     * out by failed logins has just proved who they are.
     */
    public function resetPassword(
        Request $request,
        string $email,
        string $code,
        string $newPassword,
    ): void {
        $email = strtolower(trim($email));
        $user  = $this->users->findForAuthentication($email);

        // One message for every way this can fail, for the same reason as above.
        $rejected = new ValidationException(
            ['code' => ['That code is not valid, or it has expired. Ask for a new one.']]
        );

        if ($user === null || $user['status'] !== 'active') {
            $this->audit->logAuth($request, 'password_reset_failed', null, 'no live account');
            throw $rejected;
        }

        $userId  = (int) $user['id'];
        $pending = $this->resets->liveFor($userId);

        if ($pending === null) {
            $this->audit->logAuth($request, 'password_reset_failed', $userId, 'no live code');
            throw $rejected;
        }

        if ((int) $pending['attempts'] >= self::RESET_ATTEMPTS) {
            $this->resets->closeOpen($userId);
            $this->audit->logAuth($request, 'password_reset_failed', $userId, 'too many attempts');
            throw $rejected;
        }

        if (!hash_equals(
            (string) $pending['code_hash'],
            $this->hashResetCode($userId, trim($code)),
        )) {
            $this->resets->recordAttempt((int) $pending['id']);
            $this->audit->logAuth($request, 'password_reset_failed', $userId, 'wrong code');
            throw $rejected;
        }

        // The code was right; from here the failures are about the password
        // itself and may say exactly what is wrong with it.
        $this->assertPasswordStrength($newPassword);

        if (UserRepository::verifyPassword($newPassword, (string) $user['password'])) {
            throw new ValidationException(
                ['password' => ['Choose a password you were not already using.']]
            );
        }

        Database::transaction(function () use ($userId, $newPassword): void {
            $this->users->update($userId, [
                'password'      => UserRepository::hashPassword($newPassword),
                'failed_logins' => 0,
                'locked_until'  => null,
                'updated_at'    => now(),
            ]);
            $this->resets->closeOpen($userId);
            $this->tokens->revokeAll($userId);
        });

        $this->audit->logAuth($request, 'password_reset', $userId);
    }

    /**
     * Switch the active organization on the current session.
     *
     * @return array<string,mixed>
     */
    public function switchOrganization(Request $request, int $organizationId): array
    {
        $userId     = (int) $request->userId();
        $membership = $this->rbac->membership($userId, $organizationId);

        if ($membership === null || $membership['status'] !== 'active') {
            throw new ForbiddenException('You are not a member of that organization');
        }

        $tokenId = $request->tokenId();
        if ($tokenId !== null) {
            $this->tokens->setActiveOrganization($tokenId, $organizationId);
        }

        $this->audit->logAuth($request, 'switch_organization', $userId, "org=$organizationId");

        return [
            'active_org_id' => $organizationId,
            'organization'  => $this->organizations->settings($organizationId),
            'role'          => $membership['role_slug'],
            'permissions'   => $this->rbac->permissionsForRole((int) $membership['role_id']),
        ];
    }

    // ---- helpers ----

    /**
     * The user id is mixed in so that the same six digits hash differently for
     * two people. Without it, one precomputed table of a million hashes would
     * match every row in the table at once.
     */
    private function hashResetCode(int $userId, string $code): string
    {
        return hash('sha256', $userId . ':' . $code);
    }

    /**
     * Queue the code as an email.
     *
     * organization_id is NULL: this is about a person, not a clinic, and the
     * person may belong to none. The row goes through the same worker and the
     * same retry ladder as every other notification (§20).
     */
    private function queueResetEmail(int $userId, string $email, string $name, string $code): void
    {
        $minutes = self::RESET_MINUTES;
        $first   = trim(explode(' ', trim($name))[0] ?? '');
        $greet   = $first === '' ? 'Hello,' : "Hello $first,";

        $body = "$greet\n\n"
            . "Your MediFlow password reset code is $code.\n\n"
            . "It works once and expires in $minutes minutes. If you did not ask "
            . "for it, you can ignore this message — your password has not changed.";

        try {
            Database::statement(
                'INSERT INTO notifications
                    (organization_id, user_id, channel, event, title, body,
                     to_address, status, created_at, updated_at)
                 VALUES (NULL, :uid, \'email\', \'account.password_reset\',
                         :title, :body, :to, \'queued\', :now, :now)',
                [
                    'uid'   => $userId,
                    'title' => 'Your MediFlow reset code',
                    'body'  => $body,
                    'to'    => $email,
                    'now'   => now(),
                ],
            );
        } catch (\Throwable $e) {
            // The code is already saved; a mail failure must not lose it, and
            // the person can always ask again.
            error_log('[auth] reset email not queued: ' . $e->getMessage());
        }
    }

    private function assertPasswordStrength(string $password): void
    {
        $min = (int) ($GLOBALS['__config']['auth']['password_min_length'] ?? 8);

        $problems = [];
        if (mb_strlen($password) < $min) {
            $problems[] = "must be at least $min characters";
        }
        if (preg_match('/[A-Za-z]/', $password) !== 1) {
            $problems[] = 'must contain a letter';
        }
        if (preg_match('/\d/', $password) !== 1) {
            $problems[] = 'must contain a number';
        }

        if ($problems !== []) {
            throw new ValidationException(
                ['password' => ['Password ' . implode(', ', $problems) . '.']]
            );
        }
    }

    /** @return array<string,mixed> */
    private function deviceContext(Request $request): array
    {
        return [
            'device_name' => $request->header('x-device-name'),
            'device_id'   => $request->header('x-device-id'),
            'ip'          => $request->ip(),
            'user_agent'  => $request->userAgent(),
        ];
    }

    /**
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    private function publicUser(array $user): array
    {
        unset($user['password'], $user['failed_logins'], $user['locked_until']);
        $user['is_platform_admin'] = (bool) ($user['is_platform_admin'] ?? false);
        return $user;
    }
}
