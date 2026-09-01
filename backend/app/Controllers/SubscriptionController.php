<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Services\AuditService;
use App\Services\SubscriptionService;

/**
 * The clinic's own view of its plan (§22).
 *
 * Reading is open to anyone who can see the team, because "why was I refused"
 * is a question the front desk asks as often as the owner. Changing the plan
 * is the owner's alone — it is a money decision.
 */
final class SubscriptionController extends Controller
{
    public function plans(Request $request): never
    {
        $this->ok(['plans' => SubscriptionService::for($request)->plans()]);
    }

    public function show(Request $request): never
    {
        $this->ok(SubscriptionService::for($request)->current());
    }

    public function update(Request $request): never
    {
        $data = $this->validate($request, ['plan_id' => 'required|integer']);

        $before = SubscriptionService::for($request)->current();
        $after  = SubscriptionService::for($request)->changePlan((int) $data['plan_id']);

        (new AuditService())->log(
            $request, 'update', 'subscription', (int) $after['subscription']['id'],
            ['plan' => $before['plan']['slug']],
            ['plan' => $after['plan']['slug']],
        );

        $this->ok($after);
    }
}
