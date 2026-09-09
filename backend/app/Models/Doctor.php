<?php
declare(strict_types=1);

namespace App\Models;

/** A clinician's professional profile (§9), separate from their login. */
final class Doctor extends Model
{
    public static function table(): string
    {
        return 'doctors';
    }

    /** @return list<string> */
    public static function fillable(): array
    {
        return [
            'user_id', 'specialty', 'location', 'qualification', 'license_no',
            'experience_years', 'consultation_fee', 'followup_fee', 'bio', 'room',
            'slot_minutes', 'is_accepting', 'created_at', 'updated_at',
        ];
    }
}
