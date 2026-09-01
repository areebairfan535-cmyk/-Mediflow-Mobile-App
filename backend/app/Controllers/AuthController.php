<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\ValidationException;
use App\Services\AuthService;
use App\Services\TokenService;

/**
 * POST   /api/v1/auth/register
 * POST   /api/v1/auth/login
 * POST   /api/v1/auth/refresh
 * POST   /api/v1/auth/forgot-password
 * POST   /api/v1/auth/reset-password
 * POST   /api/v1/auth/logout
 * POST   /api/v1/auth/logout-all
 * POST   /api/v1/auth/change-password
 * POST   /api/v1/auth/switch-organization
 * GET    /api/v1/auth/sessions
 * DELETE /api/v1/auth/sessions/{id}
 *
 * Note how thin each method is: validate, delegate, respond (§12).
 */
final class AuthController extends Controller
{
    public function register(Request $request): never
    {
        $data = $this->validate($request, [
            'name'     => 'required|string|min:2|max:255',
            'email'    => 'required|email|max:255',
            'password' => 'required|string|min:8|max:255',
            'phone'    => 'nullable|string|max:32',
            'locale'   => 'nullable|string|max:10',
        ]);

        $this->created((new AuthService())->register($request, $data));
    }

    public function login(Request $request): never
    {
        $data = $this->validate($request, [
            'email'           => 'required|email',
            'password'        => 'required|string',
            'organization_id' => 'nullable|integer',
        ]);

        $this->ok((new AuthService())->login($request, $data));
    }

    public function refresh(Request $request): never
    {
        $data = $this->validate($request, [
            'refresh_token' => 'required|string',
        ]);

        $this->ok((new AuthService())->refresh($request, (string) $data['refresh_token']));
    }

    /**
     * Both halves of a forgotten password are public — the whole point is that
     * the person cannot sign in. The auth throttle bucket covers them, so the
     * code cannot be guessed by volume.
     */
    public function forgotPassword(Request $request): never
    {
        $data = $this->validate($request, [
            'email' => 'required|email|max:255',
        ]);

        $this->ok((new AuthService())->forgotPassword($request, (string) $data['email']));
    }

    public function resetPassword(Request $request): never
    {
        $data = $this->validate($request, [
            'email'    => 'required|email|max:255',
            'code'     => 'required|string|min:4|max:12',
            'password' => 'required|string|min:8|max:255',
        ]);

        // The apps ask twice; if the second copy came along, it must agree.
        if (($request->body['password_confirmation'] ?? null) !== null
            && $request->body['password_confirmation'] !== $data['password']
        ) {
            throw new ValidationException(
                ['password_confirmation' => ['The two passwords are not the same.']]
            );
        }

        (new AuthService())->resetPassword(
            $request,
            (string) $data['email'],
            (string) $data['code'],
            (string) $data['password'],
        );

        $this->ok(['message' => 'Your password has been changed. Log in with the new one.']);
    }

    public function logout(Request $request): never
    {
        (new AuthService())->logout($request);
        $this->ok(['message' => 'Signed out']);
    }

    public function logoutAll(Request $request): never
    {
        $count = (new AuthService())->logoutEverywhere($request);
        $this->ok(['message' => 'Signed out of all devices', 'sessions_revoked' => $count]);
    }

    public function changePassword(Request $request): never
    {
        $data = $this->validate($request, [
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:8|max:255',
        ]);

        if (($request->body['new_password_confirmation'] ?? null) !== null
            && $request->body['new_password_confirmation'] !== $data['new_password']
        ) {
            throw new ValidationException(
                ['new_password' => ['Password confirmation does not match.']]
            );
        }

        (new AuthService())->changePassword(
            $request,
            (string) $data['current_password'],
            (string) $data['new_password'],
        );

        $this->ok(['message' => 'Password changed. Please sign in again.']);
    }

    public function switchOrganization(Request $request): never
    {
        $data = $this->validate($request, [
            'organization_id' => 'required|integer',
        ]);

        $this->ok((new AuthService())->switchOrganization(
            $request,
            (int) $data['organization_id'],
        ));
    }

    /** Device/session manager (§11). */
    public function sessions(Request $request): never
    {
        $sessions = (new TokenService())->sessions((int) $request->userId());
        $current  = $request->tokenId();

        // Flag the caller's own session so a UI can label it "this device".
        $sessions = array_map(
            static function (array $s) use ($current): array {
                $s['is_current'] = $current !== null
                    && (int) ($s['id'] ?? 0) === $current;
                return $s;
            },
            $sessions,
        );

        $this->ok(['sessions' => $sessions]);
    }

    public function revokeSession(Request $request): never
    {
        $ok = (new TokenService())->revokeSessionForUser(
            (int) $request->userId(),
            $request->intParam('id'),
        );

        if (!$ok) {
            // Do not distinguish "not yours" from "does not exist".
            throw new \App\Core\NotFoundException('Session not found');
        }

        $this->ok(['message' => 'Session revoked']);
    }
}
