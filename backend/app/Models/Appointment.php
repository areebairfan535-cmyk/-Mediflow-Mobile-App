<?php
declare(strict_types=1);

namespace App\Models;

/** A booked slot (§4). */
final class Appointment extends Model
{
    public static function table(): string
    {
        return 'appointments';
    }

    /** @return list<string> */
    public static function fillable(): array
    {
        return [
            'patient_id', 'doctor_id', 'scheduled_at', 'duration_minutes', 'type',
            'status', 'reason', 'cancelled_reason', 'rescheduled_from', 'booked_by',
            'created_by', 'updated_by', 'created_at', 'updated_at',
        ];
    }
}
