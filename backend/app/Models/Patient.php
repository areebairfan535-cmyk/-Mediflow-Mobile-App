<?php
declare(strict_types=1);

namespace App\Models;

/** A person the clinic treats (§3). Tenant scoped: a patient belongs to the clinic that registered them. */
final class Patient extends Model
{
    public static function table(): string
    {
        return 'patients';
    }

    /** @return list<string> */
    public static function fillable(): array
    {
        return [
            'user_id', 'mrn', 'first_name', 'last_name', 'date_of_birth', 'gender',
            'national_id', 'national_id_expiry',
            'phone', 'email', 'address', 'city', 'blood_group',
            'emergency_name', 'emergency_phone', 'emergency_relation',
            'notes', 'status', 'created_by', 'updated_by', 'created_at', 'updated_at',
        ];
    }
}
