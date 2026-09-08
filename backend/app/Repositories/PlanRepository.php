<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Repository;
use App\Models\Plan;

/**
 * The subscription price list (§21, §22).
 *
 * Plans belong to the platform, not to any one clinic, so this is not tenant
 * scoped — same reasoning as OrganizationRepository.
 *
 * These queries used to sit in PlatformController and PublicController.
 * §18 says controllers do not write SQL, and this is the layer that may.
 */
final class PlanRepository extends Repository
{
    protected string $model = Plan::class;

    public function findBySlug(string $slug): ?array
    {
        return $this->firstWhere(['slug' => $slug]);
    }

    public function slugExists(string $slug): bool
    {
        return $this->exists(['slug' => $slug]);
    }

    /** An active plan by slug — null if that slug is retired or unknown. */
    public function findActiveBySlug(string $slug): ?array
    {
        return $this->firstWhere(['slug' => $slug, 'is_active' => 1]);
    }

    /**
     * Every plan a clinic can choose, in full.
     *
     * As against publicList(), which is the trimmed version for people who do
     * not have an account yet.
     *
     * @return list<array<string,mixed>>
     */
    public function activeAll(): array
    {
        return $this->query(
            'SELECT * FROM plans WHERE is_active = 1 ORDER BY sort_order, price_monthly',
        );
    }

    /**
     * The entry-level plan, for a clinic that named none.
     *
     * Cheapest rather than "the one called free", because a deployment may
     * have renamed it and a clinic must still land somewhere.
     */
    public function cheapestActive(): ?array
    {
        return $this->query(
            'SELECT * FROM plans WHERE is_active = 1 ORDER BY sort_order, price_monthly LIMIT 1',
        )[0] ?? null;
    }

    /**
     * Every plan, cheapest first, each with the number of clinics on it.
     *
     * The count is here rather than in the caller because "may we retire this
     * plan" is unanswerable without it, and it is the first thing anyone asks.
     *
     * @return list<array<string,mixed>>
     */
    public function allWithSubscriberCounts(): array
    {
        return $this->query(
            'SELECT p.*,
                    (SELECT COUNT(*) FROM subscriptions s WHERE s.plan_id = p.id) AS organizations
               FROM plans p
              ORDER BY p.sort_order, p.price_monthly',
        );
    }

    /**
     * What the marketing site may show: live plans, and none of the bookkeeping
     * columns a prospective customer has no business reading.
     *
     * @return list<array<string,mixed>>
     */
    public function publicList(): array
    {
        return $this->query(
            'SELECT id, slug, name, description, price_monthly, price_yearly, currency_code,
                    max_doctors, max_staff, max_patients, max_storage_mb,
                    max_invoices_month, max_appointments_month, max_ai_calls_month, features
               FROM plans
              WHERE is_active = 1
              ORDER BY sort_order, price_monthly',
        );
    }
}
