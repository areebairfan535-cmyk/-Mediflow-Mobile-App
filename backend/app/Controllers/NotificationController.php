<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
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
