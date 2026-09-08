<?php
declare(strict_types=1);

namespace App\Models;

/** An insurance claim (§15). */
final class Claim extends Model
{
    public static function table(): string
    {
        return 'claims';
    }

    /** @return list<string> */
    public static function fillable(): array
    {
        return [
            'patient_id', 'invoice_id', 'encounter_id', 'insurance_policy_id',
            'claim_no', 'external_claim_no', 'status', 'currency_code',
            'claimed_amount', 'approved_amount', 'paid_amount', 'patient_responsibility',
            'rejection_code', 'rejection_reason', 'resubmission_of', 'submission_count',
            'ai_risk_score', 'ai_missing_items',
            'submitted_at', 'decided_at', 'paid_at',
            'created_by', 'updated_by', 'created_at', 'updated_at',
        ];
    }
}
