<?php
declare(strict_types=1);

namespace App\Models;

/**
 * A clinic's plan and billing period (§22).
 *
 * Not tenant scoped in the base-class sense: the organization id is passed
 * explicitly wherever this is read, because a subscription is written in the
 * same transaction that creates the clinic — at which point there is no bound
 * tenant to scope by.
 */
final class Subscription extends Model
{
    public static function table(): string
    {
        return 'subscriptions';
    }

    public static function tenantScoped(): bool
    {
        return false;
    }

    /** @return list<string> */
    public static function fillable(): array
    {
        return [
            'organization_id', 'plan_id', 'status', 'billing_cycle', 'currency_code',
            'amount', 'current_period_start', 'current_period_end',
            'trial_ends_at', 'cancelled_at', 'gateway', 'gateway_ref',
            'created_at', 'updated_at',
        ];
    }
}
