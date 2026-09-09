<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Repositories\DeviceTokenRepository;
use App\Services\AuditService;
use App\Services\NotificationService;

/**
 * The signed-in person's inbox (§19, §20) — whoever they are.
 *
 * §20's queue was always keyed on user_id, and staff rows were already being
 * written to it. What was missing is the door: /notifications existed only
 * under /patient, so a doctor or a receptionist had no way to read their own.
 * A message queued for somebody who cannot open it is not a notification.
 *
 * No tenant is required. A notification belongs to a person — a password reset
 * carries organization_id NULL — and someone who works at two clinics has one
 * inbox, not two.
 */
final class NotificationController extends Controller
{
    private function service(Request $request): NotificationService
    {
        return new NotificationService($request->organizationId(), $request->userId());
    }

    /**
     * Register this device so notifications reach it outside the app (§20).
     *
     * Called on every launch, not only the first: push tokens are reissued by
     * the OS from time to time, and an app that registered once would quietly
     * stop being reachable. The write upserts on the token, so calling it
     * daily costs one row, not thirty.
     */
    public function registerDevice(Request $request): never
    {
        $data = $this->validate($request, [
            'token'       => 'required|string|min:8|max:255',
            'platform'    => 'nullable|in:ios,android,web',
            'device_name' => 'nullable|string|max:120',
        ]);

        $device = (new DeviceTokenRepository())->register(
            (int) $request->userId(),
            trim((string) $data['token']),
            (string) ($data['platform'] ?? 'android'),
            $data['device_name'] ?? null,
        );

        // Worth a trail entry: a device being added is a new place this
        // person's health notifications will appear.
        (new AuditService())->log(
            $request, 'create', 'device_token', (int) ($device['id'] ?? 0), null,
            ['platform' => $device['platform'] ?? null, 'device' => $device['device_name'] ?? null],
        );

        $this->created(['device' => $device]);
    }

    /** The devices this person has registered, and which have gone quiet. */
    public function devices(Request $request): never
    {
        $this->ok([
            'devices' => (new DeviceTokenRepository())->listFor((int) $request->userId()),
        ]);
    }

    /**
     * Stop pushing to one device — "sign this phone out of notifications".
     */
    public function forgetDevice(Request $request): never
    {
        $revoked = (new DeviceTokenRepository())->revokeOwned(
            $request->intParam('id'),
            (int) $request->userId(),
            'Signed out from this device',
        );

        if (!$revoked) {
            // Either it is not theirs or it was already silenced. Same answer
            // for both — a stranger must not learn that an id exists.
            throw new \App\Core\NotFoundException('No such device on this account');
        }

        $this->ok(['message' => 'This device will stop receiving notifications.']);
    }

    public function index(Request $request): never
    {
        $q = $this->validateQuery($request, ['unread' => 'nullable|boolean']);

        $service = $this->service($request);
        $userId  = (int) $request->userId();

        $this->ok([
            'notifications' => $service->inbox($userId, (bool) ($q['unread'] ?? false)),
            'unread'        => $service->unreadCount($userId),
        ]);
    }

    public function markRead(Request $request): never
    {
        $service = $this->service($request);
        $userId  = (int) $request->userId();

        // No id in the path means "mark everything read".
        $id    = $request->param('id') !== null ? $request->intParam('id') : null;
        $count = $service->markRead($userId, $id);

        $this->ok(['marked_read' => $count, 'unread' => $service->unreadCount($userId)]);
    }

    /**
     * Clear notifications from this person's inbox.
     *
     * The rows are not deleted — "was this person told?" has to stay
     * answerable. With no id, everything already read is cleared; unread ones
     * survive, because tidying an inbox must not be how a reminder gets lost.
     */
    public function dismiss(Request $request): never
    {
        $service = $this->service($request);
        $userId  = (int) $request->userId();

        $id  = $request->param('id') !== null ? $request->intParam('id') : null;
        $ids = is_array($request->body['ids'] ?? null) ? $request->body['ids'] : null;
        $all = filter_var(
            $request->body['all'] ?? ($request->query['all'] ?? false),
            FILTER_VALIDATE_BOOL,
        );

        $count = $service->dismiss($userId, $id, $ids, $all);

        $this->ok(['dismissed' => $count, 'unread' => $service->unreadCount($userId)]);
    }
}
