<?php
declare(strict_types=1);

namespace App\Models;

/**
 * A device that has asked to be told things (§20).
 *
 * Not tenant scoped: a notification belongs to a person, and somebody who
 * works at two clinics carries one phone. The same reasoning as Notification.
 */
final class DeviceToken extends Model
{
    public static function table(): string
    {
        return 'device_tokens';
    }

    public static function tenantScoped(): bool
    {
        return false;
    }

    /** @return list<string> */
    public static function fillable(): array
    {
        return [
            'user_id', 'token', 'platform', 'device_name',
            'last_seen_at', 'revoked_at', 'last_error',
            'created_at', 'updated_at',
        ];
    }

    /**
     * The token itself is a delivery address, not a secret in the way a
     * password is — but it is still nobody's business but the owner's, and a
     * leaked one lets a stranger push notifications at that phone.
     *
     * @return list<string>
     */
    public static function hidden(): array
    {
        return ['token'];
    }
}
