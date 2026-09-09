<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Repository;
use App\Models\Subscription;

/**
 * Subscriptions and the usage counted against them (§22).
 *
 * Moved out of SubscriptionService per §18. Not tenant scoped in the base-class
 * sense: the organization id is passed in explicitly on every call, because
 * some of these run for a clinic that is being created and has no bound tenant
 * yet — onboarding writes its subscription in the same transaction that writes
 * the clinic.
 */
final class SubscriptionRepository extends Repository
{
    protected string $model = Subscription::class;

    /**
     * The live subscription for a clinic.
     *
     * Ordered by status rather than by id alone: a clinic that cancelled and
     * signed up again has two rows, and the active one is the answer even if
     * the cancelled row was touched more recently.
     *
     * @return array<string,mixed>|null
     */
    public function activeFor(int $organizationId): ?array
    {
        return Database::selectOne(
            'SELECT * FROM subscriptions
              WHERE organization_id = :org
              ORDER BY FIELD(status, \'active\', \'trialing\', \'past_due\', \'cancelled\', \'expired\'), id DESC
              LIMIT 1',
            ['org' => $organizationId],
        );
    }

    /** @return array<string,mixed>|null */
    public function latestFor(int $organizationId): ?array
    {
        return Database::selectOne(
            'SELECT * FROM subscriptions WHERE organization_id = :org ORDER BY id DESC LIMIT 1',
            ['org' => $organizationId],
        );
    }

    public function start(
        int $organizationId,
        int $planId,
        string $currency,
        string $amount,
        string $periodStart,
        string $periodEnd,
    ): void {
        Database::statement(
            'INSERT INTO subscriptions
                (organization_id, plan_id, status, billing_cycle, currency_code, amount,
                 current_period_start, current_period_end, created_at, updated_at)
             VALUES (:org, :plan, \'active\', \'monthly\', :currency, :amount,
                     :start, :end, :now, :now)',
            [
                'org'      => $organizationId,
                'plan'     => $planId,
                'currency' => $currency,
                'amount'   => $amount,
                'start'    => $periodStart,
                'end'      => $periodEnd,
                'now'      => now(),
            ],
        );
    }

    /** Move a subscription onto a different plan. */
    public function movePlan(int $subscriptionId, int $planId, string $amount, string $currency): void
    {
        Database::statement(
            'UPDATE subscriptions
                SET plan_id = :plan, amount = :amount, currency_code = :currency,
                    status = :status, updated_at = :now
              WHERE id = :id',
            [
                'plan'     => $planId,
                'amount'   => $amount,
                'currency' => $currency,
                // Choosing a plan ends any trial: the clinic has decided.
                'status'   => 'active',
                'now'      => now(),
                'id'       => $subscriptionId,
            ],
        );
    }

    /**
     * Add to a metered counter for the current period.
     *
     * Upsert rather than read-then-write: two AI calls landing together must
     * both be counted, and the unique key on (subscription, metric, period) is
     * what makes that safe.
     */
    public function addUsage(
        int $organizationId,
        int $subscriptionId,
        string $metric,
        string $periodStart,
        string $periodEnd,
        ?int $includedQty,
        int $quantity,
    ): void {
        Database::statement(
            'INSERT INTO subscription_items
                (organization_id, subscription_id, metric, period_start, period_end,
                 included_qty, used_qty, created_at, updated_at)
             VALUES (:org, :sub, :metric, :start, :end, :included, :qty, :now, :now)
             ON DUPLICATE KEY UPDATE used_qty = used_qty + VALUES(used_qty),
                                     updated_at = VALUES(updated_at)',
            [
                'org'      => $organizationId,
                'sub'      => $subscriptionId,
                'metric'   => $metric,
                'start'    => $periodStart,
                'end'      => $periodEnd,
                'included' => $includedQty,
                'qty'      => $quantity,
                'now'      => now(),
            ],
        );
    }

    /**
     * The currency a clinic's subscription should be billed in (§23).
     *
     * A clinic's own currency column is NULL until it overrides the market's,
     * so this reads the resolved value — not the column, which would stamp a
     * Karachi clinic's subscription in USD.
     */
    public function billingCurrency(int $organizationId): ?string
    {
        $row = Database::selectOne(
            'SELECT COALESCE(o.currency_code, c.currency_code) AS currency_code
               FROM organizations o
               JOIN countries c ON c.id = o.country_id
              WHERE o.id = :id',
            ['id' => $organizationId],
        );

        return $row['currency_code'] ?? null;
    }

    // ---------------------------------------------------------------
    // Usage counters (§22)
    // ---------------------------------------------------------------

    /**
     * How much of one metric a clinic is using.
     *
     * The date bounds are a half-open range: appointments and invoices carry
     * DATETIMEs, and "<= end date" would drop everything created on the last
     * day of the period.
     */
    public function usage(
        int $organizationId,
        string $metric,
        string $periodStart,
        string $from,
        string $to,
    ): int {
        return match ($metric) {
            'doctors' => $this->scalar(
                'SELECT COUNT(*) c FROM doctors WHERE organization_id = :org',
                ['org' => $organizationId],
            ),
            'staff' => $this->scalar(
                'SELECT COUNT(*) c FROM organization_users
                  WHERE organization_id = :org AND status = \'active\'',
                ['org' => $organizationId],
            ),
            'patients' => $this->scalar(
                'SELECT COUNT(*) c FROM patients
                  WHERE organization_id = :org AND status = \'active\'',
                ['org' => $organizationId],
            ),
            'storage' => (int) ceil(
                $this->scalar(
                    'SELECT COALESCE(SUM(size_bytes), 0) c FROM medical_documents
                      WHERE organization_id = :org',
                    ['org' => $organizationId],
                ) / 1048576,
            ),
            'appointments' => $this->scalar(
                'SELECT COUNT(*) c FROM appointments
                  WHERE organization_id = :org AND created_at >= :from AND created_at < :to',
                ['org' => $organizationId, 'from' => $from, 'to' => $to],
            ),
            'invoices' => $this->scalar(
                'SELECT COUNT(*) c FROM invoices
                  WHERE organization_id = :org AND created_at >= :from AND created_at < :to',
                ['org' => $organizationId, 'from' => $from, 'to' => $to],
            ),
            // No source table — this is what subscription_items is for.
            'ai_calls' => $this->scalar(
                'SELECT COALESCE(SUM(used_qty), 0) c FROM subscription_items
                  WHERE organization_id = :org AND metric = \'ai_calls\'
                    AND period_start = :start',
                ['org' => $organizationId, 'start' => $periodStart],
            ),
            default => 0,
        };
    }

    /** @param array<string,mixed> $bindings */
    private function scalar(string $sql, array $bindings): int
    {
        $row = Database::selectOne($sql, $bindings);
        return (int) ($row['c'] ?? 0);
    }
}
