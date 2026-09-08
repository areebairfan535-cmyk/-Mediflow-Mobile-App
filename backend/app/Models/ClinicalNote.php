<?php
declare(strict_types=1);

namespace App\Models;

/**
 * A note on the chart (§5, §28).
 *
 * `is_ai_drafted` and `approved_by` are the pair that matter. A draft the
 * machine wrote is not part of the record until a clinician signs it off, and
 * nothing in the patient's own export or app shows an unapproved one. That is
 * why approval is a column here rather than a status somewhere else: the
 * question "did a person agree to this?" has to be answerable from the row.
 */
final class ClinicalNote extends Model
{
    public static function table(): string
    {
        return 'clinical_notes';
    }

    /** @return list<string> */
    public static function fillable(): array
    {
        return [
            'encounter_id', 'patient_id', 'type', 'body', 'is_ai_drafted',
            'approved_by', 'approved_at', 'created_by', 'updated_by',
            'created_at', 'updated_at',
        ];
    }
}
