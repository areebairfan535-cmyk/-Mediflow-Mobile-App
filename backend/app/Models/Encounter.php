<?php
declare(strict_types=1);

namespace App\Models;

/** One visit (§8) — the consultation itself, and the spine the clinical record hangs off. */
final class Encounter extends Model
{
    public static function table(): string
    {
        return 'encounters';
    }

    /** @return list<string> */
    public static function fillable(): array
    {
        return [
            'patient_id', 'doctor_id', 'appointment_id', 'encounter_no', 'type', 'status',
            'chief_complaint', 'symptoms', 'examination',
            'bp_systolic', 'bp_diastolic', 'pulse', 'temperature_c', 'weight_kg', 'height_cm',
            'followup_on', 'started_at', 'completed_at',
            'created_by', 'updated_by', 'created_at', 'updated_at',
        ];
    }
}
