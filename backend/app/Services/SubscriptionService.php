<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\NotFoundException;
use App\Core\PlanLimitException;
use App\Core\Service;
use App\Core\ValidationException;
use App\Repositories\PlanRepository;
use App\Repositories\SubscriptionRepository;

/**
 * The SaaS subscription model (§22) — plans, what each one allows, and how
 * much of it an organization has used.
 *
 * Two shapes of limit, and they are counted differently:
 *
 *   - **Standing** (doctors, staff, patients, storage) — how many exist right
 *     now. Counted from the source tables, because a counter that drifts from
 *     the thing it counts is worse than no counter: a clinic that deletes a
 *     doctor must get that seat back immediately.
 *   - **Metered** (appointments, invoices, AI calls per month) — how many
 *     happened this billing period. Appointments and invoices are counted from
 *     their own tables by date; AI calls have no table of their own, so they
 *     are tallied in `subscription_items`, which exists for exactly this.
 *
 * NULL anywhere in a plan means unlimited. Enterprise is all NULLs.
 *
 * Everything here reads the organization's own row, so no method needs a
 * tenant guard beyond the one the caller already passed.
 */
final class SubscriptionService extends Service
{
    /** metric => human label, in the order a plan card should show them. */
    public const METRICS = [
        'doctors'      => 'Doctors',
        'staff'        => 'Staff accounts',
        'patients'     => 'Patients',
        'storage'      => 'Document storage (MB)',
        'appointments' => 'Appointments this month',
        'invoices'     => 'Invoices this month',
        'ai_calls'     => 'AI assistant calls this month',
    ];

    /** plan column holding each metric's ceiling. */
    private const LIMIT_COLUMN = [
        'doctors'      => 'max_doctors',
        'staff'        => 'max_staff',
        'patients'     => 'max_patients',
        'storage'      => 'max_storage_mb',
        'appointments' => 'max_appointments_month',
        'invoices'     => 'max_invoices_month',
        'ai_calls'     => 'max_ai_calls_month',
    ];

    // ---------------------------------------------------------------
    // Reading
    // ---------------------------------------------------------------

    /** @return list<array<string,mixed>> every plan a clinic can choose. */
    public function plans(): array
    {
        return (new PlanRepository())->activeAll();
    }

    /**
     * The organization's subscription, its plan, and usage against every limit.
     *
     * @return array<string,mixed>
     */
    public function current(?int $organizationId = null): array
    {
        $orgId        = $organizationId ?? $this->requireOrganization();
        $subscription = $this->subscriptionFor($orgId);
        $plan         = $this->planById((int) $subscription['plan_id']);

        $usage = [];
        foreach (self::METRICS as $metric => $label) {
            $limit = $plan[self::LIMIT_COLUMN[$metric]] ?? null;
            $limit = $limit === null ? null : (int) $limit;
            $used  = $this->used($orgId, $metric, $subscription);

            $usage[] = [
                'metric'    => $metric,
                'label'     => $label,
                'used'      => $used,
                'limit'     => $limit,
                'unlimited' => $limit === null,
                'remaining' => $limit === null ? null : max(0, $limit - $used),
                // Rendering this is the client's job, but deciding when a
                // clinic should be warned is a product rule, so it lives here.
                'exhausted' => $limit !== null && $used >= $limit,
            ];
        }

        return [
            'subscription' => $subscription,
            'plan'         => $this->presentPlan($plan),
            'usage'        => $usage,
        ];
    }

    // ---------------------------------------------------------------
    // Enforcement
    // ---------------------------------------------------------------

    /**
     * Refuse the write if the plan has no room for it.
     *
     * Called at the top of the create paths rather than inside the repository:
     * the check needs to happen once per user action, not once per row, and a
     * bulk import that half-succeeds is worse than one that is refused.
     *
     * @param int $adding how many of the thing are about to be created
     */
    public function assertWithin(string $metric, int $adding = 1, ?int $organizationId = null): void
    {
        $orgId = $organizationId ?? $this->requireOrganization();

        if (!isset(self::LIMIT_COLUMN[$metric])) {
            throw new ValidationException(['metric' => ["Unknown plan metric \"$metric\"."]]);
        }

        $subscription = $this->subscriptionFor($orgId);
        $plan         = $this->planById((int) $subscription['plan_id']);
        $limit        = $plan[self::LIMIT_COLUMN[$metric]] ?? null;

        if ($limit === null) {
            return;                       // unlimited
        }

        $limit = (int) $limit;
        $used  = $this->used($orgId, $metric, $subscription);

        if ($used + $adding <= $limit) {
            return;
        }

        throw new PlanLimitException(
            sprintf(
                '%s: the %s plan allows %d and %d %s already in use. Upgrade the plan to continue.',
                self::METRICS[$metric],
                $plan['name'],
                $limit,
                $used,
                $used === 1 ? 'is' : 'are',
            ),
            $metric,
            $limit,
            $used,
        );
    }

    /**
     * Tally one unit of a metered thing that has no table of its own.
     *
     * Only AI calls need this today. It is written as an upsert on the period
     * row so two simultaneous calls cannot lose a count.
     */
    public function recordUsage(string $metric, int $quantity = 1, ?int $organizationId = null): void
    {
        $orgId        = $organizationId ?? $this->requireOrganization();
        $subscription = $this->subscriptionFor($orgId);
        [$start, $end] = $this->period($subscription);

        $plan  = $this->planById((int) $subscription['plan_id']);
        $limit = $plan[self::LIMIT_COLUMN[$metric] ?? ''] ?? null;

        $this->subscriptions()->addUsage(
            $orgId,
            (int) $subscription['id'],
            $metric,
            $start,
            $end,
            $limit === null ? null : (int) $limit,
            $quantity,
        );
    }

    // ---------------------------------------------------------------
    // Writing
    // ---------------------------------------------------------------

    /**
     * Move the organization to another plan (§22).
     *
     * A downgrade below what the clinic is already using is refused rather
     * than silently deleting doctors or patients to fit — the clinic must
     * reduce first, and be told exactly what is in the way.
     *
     * @return array<string,mixed>
     */
    public function changePlan(int $planId, ?int $organizationId = null): array
    {
        $orgId = $organizationId ?? $this->requireOrganization();
        $plan  = $this->planById($planId);

        if ((int) ($plan['is_active'] ?? 0) !== 1) {
            throw new ValidationException(['plan_id' => ['That plan is no longer offered.']]);
        }

        $subscription = $this->subscriptionFor($orgId);
        $blocking     = [];

        foreach (self::METRICS as $metric => $label) {
            $limit = $plan[self::LIMIT_COLUMN[$metric]] ?? null;
            if ($limit === null) {
                continue;
            }
            $used = $this->used($orgId, $metric, $subscription);
            if ($used > (int) $limit) {
                $blocking[] = sprintf('%s: %d in use, %s allows %d', $label, $used, $plan['name'], $limit);
            }
        }

        if ($blocking !== []) {
            throw new ValidationException(
                ['plan_id' => $blocking],
                'This plan is smaller than what the organization already uses.',
            );
        }

        $this->subscriptions()->movePlan(
            (int) $subscription['id'],
            $planId,
            (string) $plan['price_monthly'],
            (string) $plan['currency_code'],
        );

        return $this->current($orgId);
    }

    /**
     * Give a brand-new organization its subscription (§22 onboarding).
     *
     * Called inside the organization-creation transaction, so a clinic can
     * never exist without an envelope — every limit check would otherwise have
     * to invent one, and inventing one silently is how a free account quietly
     * becomes unlimited.
     *
     * @return array<string,mixed> the subscription row
     */
    public static function startFor(int $organizationId, string $currency, ?string $planSlug = null): array
    {
        $plans = new PlanRepository();
        $plan  = $plans->findActiveBySlug($planSlug ?? 'free') ?? $plans->cheapestActive();

        if ($plan === null) {
            throw new NotFoundException('No subscription plan is configured — run database/seed.php');
        }

        $subscriptions = new SubscriptionRepository();

        $subscriptions->start(
            $organizationId,
            (int) $plan['id'],
            $currency,
            (string) $plan['price_monthly'],
            gmdate('Y-m-01'),
            gmdate('Y-m-t'),
        );

        return $subscriptions->latestFor($organizationId) ?? [];
    }

    // ---------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------

    private function subscriptions(): SubscriptionRepository
    {
        return new SubscriptionRepository();
    }

    /** @return array<string,mixed> */
    private function subscriptionFor(int $orgId): array
    {
        $subscriptions = $this->subscriptions();
        $subscription  = $subscriptions->activeFor($orgId);

        if ($subscription !== null) {
            return $subscription;
        }

        // An organization created before this module existed has no row. Give
        // it the free plan rather than treating "no subscription" as "no
        // limits" — the safer reading of a missing record. The currency comes
        // from the resolved market value, so a Karachi clinic is not stamped
        // in USD (§23).
        return self::startFor($orgId, $subscriptions->billingCurrency($orgId) ?? 'USD');
    }

    /** @return array<string,mixed> */
    private function planById(int $planId): array
    {
        $plan = (new PlanRepository())->find($planId);
        if ($plan === null) {
            throw new NotFoundException('Plan not found');
        }
        return $plan;
    }

    /** Decode the features JSON so clients do not each parse it themselves. */
    private function presentPlan(array $plan): array
    {
        $plan['features'] = is_string($plan['features'] ?? null)
            ? (json_decode($plan['features'], true) ?: [])
            : ($plan['features'] ?? []);
        return $plan;
    }

    /** @return array{0:string,1:string} the current period as [start, end] dates. */
    private function period(array $subscription): array
    {
        $start = $subscription['current_period_start'] ?? null;
        $end   = $subscription['current_period_end'] ?? null;

        // A period that has run out is rolled forward to the current calendar
        // month rather than left in the past, which would freeze every metered
        // count at whatever it was when the period ended.
        if ($start === null || $end === null || $end < gmdate('Y-m-d')) {
            return [gmdate('Y-m-01'), gmdate('Y-m-t')];
        }

        return [(string) $start, (string) $end];
    }

    /** How much of one metric is in use right now. */
    private function used(int $orgId, string $metric, array $subscription): int
    {
        [$start, $end] = $this->period($subscription);

        // Half-open UTC range: appointments and invoices carry DATETIMEs, and
        // "<= end date" would drop everything created on the last day.
        $from = $start . ' 00:00:00';
        $to   = gmdate('Y-m-d H:i:s', strtotime($end . ' 00:00:00 +1 day'));

        return $this->subscriptions()->usage($orgId, $metric, $start, $from, $to);
    }
}
