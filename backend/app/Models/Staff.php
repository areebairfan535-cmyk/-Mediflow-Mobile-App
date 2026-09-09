<?php
declare(strict_types=1);

namespace App\Models;

/**
 * A staff member's employment record (§20).
 *
 * The parallel of Doctor, for everyone who is not a clinician. Doctor holds
 * what a patient needs to choose one — specialty, fee, room. This holds what
 * the clinic needs to run: employee number, department, designation, hire date.
 *
 * Kept apart from organization_users on purpose. That row answers "may this
 * person do this?" — role and status, read on every request. This one answers
 * "who works here and since when?", which no request path needs and which
 * outlives any particular role: somebody promoted from receptionist to office
 * manager keeps their employee number and their start date.
 *
 * Not tenant scoped in the base-class sense: like Membership, the organization
 * is part of the key being looked up rather than a filter over it.
 */
final class Staff extends Model
{
    public static function table(): string
    {
        return 'staff';
    }

    public static function tenantScoped(): bool
    {
        return false;
    }

    /** @return list<string> */
    public static function fillable(): array
    {
        return [
            'organization_id', 'user_id', 'employee_no', 'department',
            'designation', 'hired_at', 'created_at', 'updated_at',
        ];
    }
}
