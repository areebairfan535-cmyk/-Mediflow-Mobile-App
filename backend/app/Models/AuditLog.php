<?php
declare(strict_types=1);

namespace App\Models;

/**
 * One line of the trail (§16).
 *
 * No updated_at, and no repository method that writes one: an audit row that
 * can be edited is not an audit row. What happened, who did it, from where,
 * and when — written once, then left alone.
 */
final class AuditLog extends Model
{
    public static function table(): string
    {
        return 'audit_logs';
    }

    public static function timestamps(): bool
    {
        return false;
    }

    /** @return list<string> */
    public static function fillable(): array
    {
        return [
            'organization_id', 'user_id', 'action', 'resource_type', 'resource_id',
            'patient_id', 'old_values', 'new_values', 'route', 'method',
            'ip_address', 'user_agent', 'request_id', 'created_at',
        ];
    }
}
