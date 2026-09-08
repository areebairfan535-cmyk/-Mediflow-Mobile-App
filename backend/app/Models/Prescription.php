<?php
declare(strict_types=1);

namespace App\Models;

/** A digital prescription (§9). */
final class Prescription extends Model
{
    public static function table(): string
    {
        return 'prescriptions';
    }

    /** @return list<string> */
    public static function fillable(): array
    {
        return [
            'encounter_id', 'patient_id', 'doctor_id', 'prescription_no', 'status',
            'general_advice', 'pdf_path', 'issued_at',
            'created_by', 'updated_by', 'created_at', 'updated_at',
        ];
    }
}
