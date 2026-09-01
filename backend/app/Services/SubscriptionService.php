<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\NotFoundException;
use App\Core\PlanLimitException;
use App\Core\Service;
use App\Core\ValidationException;

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
        return Database::select(
            'SELECT * FROM plans WHERE is_active = 1 ORDER BY sort_order, price_monthly',
        );
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

        Database::statement(
            'INSERT INTO subscription_items
                (organization_id, subscription_id, metric, period_start, period_end,
                 included_qty, used_qty, created_at, updated_at)
             VALUES (:org, :sub, :metric, :start, :end, :included, :qty, :now, :now)
             ON DUPLICATE KEY UPDATE used_qty = used_qty + VALUES(used_qty),
                                     updated_at = VALUES(updated_at)',
            [
                'org'      => $orgId,
                'sub'      => (int) $subscription['id'],
                'metric'   => $metric,
                'start'    => $start,
                'end'      => $end,
                'included' => $limit === null ? null : (int) $limit,
                'qty'      => $quantity,
                'now'      => now(),
            ],
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

        Database::statement(
            'UPDATE subscriptions
                SET plan_id = :plan, amount = :amount, currency_code = :currency,
                    status = :status, updated_at = :now
              WHERE id = :id',
            [
                'plan'     => $planId,
                'amount'   => $plan['price_monthly'],
                'currency' => $plan['currency_code'],
                // Choosing a plan ends any trial: the clinic has decided.
                'status'   => 'active',
                'now'      => now(),
                'id'       => (int) $subscription['id'],
            ],
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
        $plan = Database::selectOne(
            'SELECT * FROM plans WHERE slug = :slug AND is_active = 1',
            ['slug' => $planSlug ?? 'free'],
        ) ?? Database::selectOne(
            'SELECT * FROM plans WHERE is_active = 1 ORDER BY sort_order, price_monthly LIMIT 1',
        );

        if ($plan === null) {
            throw new NotFoundException('No subscription plan is configured — run database/seed.php');
        }

        Database::statement(
            'INSERT INTO subscriptions
                (organization_id, plan_id, status, billing_cycle, currency_code, amount,
                 current_period_start, current_period_end, created_at, updated_at)
             VALUES (:org, :plan, \'active\', \'monthly\', :currency, :amount,
                     :start, :end, :now, :now)',
            [
                'org'      => $organizationId,
                'plan'     => (int) $plan['id'],
                'currency' => $currency,
                'amount'   => $plan['price_monthly'],
                'start'    => gmdate('Y-m-01'),
                'end'      => gmdate('Y-m-t'),
                'now'      => now(),
            ],
        );

        return Database::selectOne(
            'SELECT * FROM subscriptions WHERE organization_id = :org ORDER BY id DESC LIMIT 1',
            ['org' => $organizationId],
        ) ?? [];
    }

    // ---------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------

    /** @return array<string,mixed> */
    private function subscriptionFor(int $orgId): array
    {
        $subscription = Database::selectOne(
            'SELECT * FROM subscriptions
              WHERE organization_id = :org
              ORDER BY FIELD(status, \'active\', \'trialing\', \'past_due\', \'cancelled\', \'expired\'), id DESC
              LIMIT 1',
            ['org' => $orgId],
        );

        if ($subscription !== null) {
            return $subscription;
        }

        // An organization created before this module existed has no row. Give
        // it the free plan rather than treating "no subscription" as "no
        // limits" — the safer reading of a missing record.
        // A clinic's own currency column is NULL until it overrides the
        // market's, so read the resolved value — not the column, which would
        // stamp a Karachi clinic's subscription in USD.
        $organization = Database::selectOne(
            'SELECT COALESCE(o.currency_code, c.currency_code) AS currency_code
               FROM organizations o
               JOIN countries c ON c.id = o.country_id
              WHERE o.id = :id',
            ['id' => $orgId],
        );

        return self::startFor($orgId, (string) ($organization['currency_code'] ?? 'USD'));
    }

    /** @return array<string,mixed> */
    private function planById(int $planId): array
    {
        $plan = Database::selectOne('SELECT * FROM plans WHERE id = :id', ['id' => $planId]);
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

        return match ($metric) {
            'doctors' => $this->count(
                'SELECT COUNT(*) c FROM doctors WHERE organization_id = :org',
                ['org' => $orgId],
            ),
            'staff' => $this->count(
                'SELECT COUNT(*) c FROM organization_users
                  WHERE organization_id = :org AND status = \'active\'',
                ['org' => $orgId],
            ),
            'patients' => $this->count(
                'SELECT COUNT(*) c FROM patients
                  WHERE organization_id = :org AND status = \'active\'',
                ['org' => $orgId],
            ),
            'storage' => (int) ceil(
                $this->count(
                    'SELECT COALESCE(SUM(size_bytes), 0) c FROM medical_documents
                      WHERE organization_id = :org',
                    ['org' => $orgId],
                ) / 1048576,
            ),
            'appointments' => $this->count(
                'SELECT COUNT(*) c FROM appointments
                  WHERE organization_id = :org AND created_at >= :from AND created_at < :to',
                ['org' => $orgId, 'from' => $from, 'to' => $to],
            ),
            'invoices' => $this->count(
                'SELECT COUNT(*) c FROM invoices
                  WHERE organization_id = :org AND created_at >= :from AND created_at < :to',
                ['org' => $orgId, 'from' => $from, 'to' => $to],
            ),
            // No source table — this is what subscription_items is for.
            'ai_calls' => $this->count(
                'SELECT COALESCE(SUM(used_qty), 0) c FROM subscription_items
                  WHERE organization_id = :org AND metric = \'ai_calls\'
                    AND period_start = :start',
                ['org' => $orgId, 'start' => $start],
            ),
            default => 0,
        };
    }

    /** @param array<string,mixed> $bindings */
    private function count(string $sql, array $bindings): int
    {
        $row = Database::selectOne($sql, $bindings);
        return (int) ($row['c'] ?? 0);
    }
}
