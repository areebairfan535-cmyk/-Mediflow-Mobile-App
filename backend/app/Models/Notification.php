<?php
declare(strict_types=1);

namespace App\Models;

/**
 * One queued message (§20).
 *
 * Rows are queued, not sent: a clinic must not wait on an SMTP timeout to
 * finish booking an appointment, so delivery is a separate worker. In-app
 * notifications need no delivery at all — the patient app reads this table.
 *
 * Not tenant scoped: organization_id is NULL on account-level messages such as
 * a password-reset code, which belong to a person rather than to a clinic, and
 * scoping the table would hide exactly those.
 */
final class Notification extends Model
{
    public static function table(): string
    {
        return 'notifications';
    }

    public static function tenantScoped(): bool
    {
        return false;
    }

    /** @return list<string> */
    public static function fillable(): array
    {
        return [
            'organization_id', 'user_id', 'channel', 'event', 'title', 'body',
            'subject_type', 'subject_id', 'payload', 'to_address', 'status',
            'attempts', 'error', 'scheduled_for', 'sent_at', 'read_at',
            'dismissed_at', 'created_at', 'updated_at',
        ];
    }
}
