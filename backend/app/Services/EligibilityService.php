<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\NotFoundException;
use App\Core\Service;
use App\Repositories\InsuranceRepository;
use App\Repositories\InvoiceRepository;
use App\Repositories\PatientRepository;
use App\Services\Billing\Money;

/**
 * Eligibility and the coverage split (§8, §25 "eligibility").
 *
 * Answers one question: of this bill, how much does the insurer owe and how
 * much does the patient?
 *
 * The order the deductions apply in is what makes the answer right or wrong,
 * so it is stated once here and used everywhere:
 *
 *   1. deductible   — the patient pays this slice first
 *   2. copay        — the patient pays their percentage of what remains
 *   3. ceiling      — the insurer never pays past the policy's remaining cover
 *   4. the rest lands on the patient
 *
 * Every figure is returned in a breakdown, not just the two totals, because a
 * billing clerk arguing with an insurer needs to show the working.
 */
final class EligibilityService extends Service
{
    private function insurance(): InsuranceRepository
    {
        return new InsuranceRepository();
    }

    private function invoices(): InvoiceRepository
    {
        return (new InvoiceRepository())->forOrganization($this->requireOrganization());
    }

    /**
     * @return array<string,mixed>|null the policy in force, or null
     */
    public function activePolicy(int $patientId, ?string $onDate = null): ?array
    {
        return $this->insurance()->activePolicyFor(
            $this->requireOrganization(),
            $patientId,
            $onDate,
        );
    }

    /**
     * Can this policy be claimed against at all?
     *
     * @param array<string,mixed> $policy
     * @return array{eligible: bool, reasons: list<string>}
     */
    public function checkPolicy(array $policy, ?string $onDate = null): array
    {
        $date    = $onDate ?? gmdate('Y-m-d');
        $reasons = [];

        if ($policy['status'] !== 'active') {
            $reasons[] = "The policy is {$policy['status']}.";
        }
        if (!empty($policy['valid_from']) && $policy['valid_from'] > $date) {
            $reasons[] = "Cover does not start until {$policy['valid_from']}.";
        }
        if (!empty($policy['valid_to']) && $policy['valid_to'] < $date) {
            $reasons[] = "Cover expired on {$policy['valid_to']}.";
        }

        if ($policy['coverage_amount'] !== null) {
            $remaining = Money::subtract(
                (string) $policy['coverage_amount'],
                (string) $policy['coverage_used'],
            );
            if (Money::compare($remaining, '0') <= 0) {
                $reasons[] = 'The annual coverage ceiling is already used up.';
            }
        }

        return ['eligible' => $reasons === [], 'reasons' => $reasons];
    }

    /**
     * Split an amount between insurer and patient.
     *
     * @param array<string,mixed> $policy
     * @return array<string,mixed>
     */
    public function split(string $amount, array $policy, ?string $onDate = null): array
    {
        $check  = $this->checkPolicy($policy, $onDate);
        $amount = Money::round($amount);

        if (!$check['eligible']) {
            return [
                'eligible'               => false,
                'reasons'                => $check['reasons'],
                'billed'                 => $amount,
                'deductible_applied'     => '0.00',
                'copay_percent'          => Money::round($policy['copay_percent'] ?? 0),
                'copay_amount'           => '0.00',
                'capped_by_ceiling'      => '0.00',
                'insurance_payable'      => '0.00',
                'patient_responsibility' => $amount,
            ];
        }

        // 1. Deductible.
        //
        // The schema carries a flat `deductible` with no "amount met so far"
        // column, so it is treated as consumed by the FIRST claim on the
        // policy: once coverage_used has moved, the deductible is behind us.
        // Tracking a running deductible would need its own column, and
        // inventing one silently would be worse than saying so.
        $deductible = Money::round($policy['deductible'] ?? 0);
        $applyDeductible = !Money::isZero($deductible)
            && Money::isZero((string) ($policy['coverage_used'] ?? 0));

        $deductibleApplied = $applyDeductible
            ? (Money::greaterThan($deductible, $amount) ? $amount : $deductible)
            : '0.00';

        $afterDeductible = Money::subtract($amount, $deductibleApplied);

        // 2. Copay — the patient's percentage of what is left.
        $copayPercent = Money::round($policy['copay_percent'] ?? 0);
        $copayAmount  = Money::isZero($copayPercent)
            ? '0.00'
            : Money::round(Money::percentOf($afterDeductible, $copayPercent));

        $insurerShare = Money::subtract($afterDeductible, $copayAmount);

        // 3. Ceiling — never claim past what the policy has left.
        $cappedBy = '0.00';
        if ($policy['coverage_amount'] !== null) {
            $remaining = Money::round(Money::subtract(
                (string) $policy['coverage_amount'],
                (string) $policy['coverage_used'],
            ));
            if (Money::greaterThan($insurerShare, $remaining)) {
                $cappedBy     = Money::round(Money::subtract($insurerShare, $remaining));
                $insurerShare = $remaining;
            }
        }

        $insurerShare = Money::round($insurerShare);

        // 4. Whatever the insurer does not take is the patient's.
        $patientShare = Money::round(Money::subtract($amount, $insurerShare));

        return [
            'eligible'               => true,
            'reasons'                => [],
            'billed'                 => $amount,
            'deductible_applied'     => $deductibleApplied,
            'copay_percent'          => $copayPercent,
            'copay_amount'           => $copayAmount,
            'capped_by_ceiling'      => $cappedBy,
            'insurance_payable'      => $insurerShare,
            'patient_responsibility' => $patientShare,
        ];
    }

    /**
     * Quote an invoice against the patient's policy, without changing anything.
     *
     * This is the "check eligibility" a receptionist runs before treatment.
     *
     * @return array<string,mixed>
     */
    public function quoteInvoice(int $invoiceId, ?int $policyId = null): array
    {
        $invoice = $this->invoices()->findOrFail($invoiceId, 'Invoice');
        $org     = $this->requireOrganization();
        $date    = $invoice['issue_date'] ?? gmdate('Y-m-d');

        // Fall back to the latest policy of any state, so an expired one is
        // reported as expired rather than as "no insurance on file".
        $policy = $policyId !== null
            ? $this->insurance()->findPolicy($org, $policyId)
            : ($this->activePolicy((int) $invoice['patient_id'], $date)
               ?? $this->insurance()->latestPolicyFor($org, (int) $invoice['patient_id']));

        if ($policy === null) {
            return [
                'invoice'  => $invoice,
                'policy'   => null,
                'coverage' => [
                    'eligible'               => false,
                    'reasons'                => ['This patient has no insurance policy on file.'],
                    'billed'                 => Money::round($invoice['grand_total']),
                    'insurance_payable'      => '0.00',
                    'patient_responsibility' => Money::round($invoice['grand_total']),
                ],
            ];
        }

        if ((int) $policy['patient_id'] !== (int) $invoice['patient_id']) {
            throw new NotFoundException('That policy belongs to a different patient.');
        }

        return [
            'invoice'  => $invoice,
            'policy'   => $policy,
            'coverage' => $this->split((string) $invoice['grand_total'], $policy, $date),
        ];
    }

    /**
     * Quote by patient, for the pre-treatment check where no invoice exists
     * yet — "if this costs X, what will they owe?".
     *
     * @return array<string,mixed>
     */
    public function quoteAmount(int $patientId, string $amount): array
    {
        (new PatientRepository())
            ->forOrganization($this->requireOrganization())
            ->findOrFail($patientId, 'Patient');

        $policy = $this->activePolicy($patientId)
            ?? $this->insurance()->latestPolicyFor($this->requireOrganization(), $patientId);

        if ($policy === null) {
            return [
                'policy'   => null,
                'coverage' => [
                    'eligible'               => false,
                    'reasons'                => ['No policy on file.'],
                    'billed'                 => Money::round($amount),
                    'insurance_payable'      => '0.00',
                    'patient_responsibility' => Money::round($amount),
                ],
            ];
        }

        return ['policy' => $policy, 'coverage' => $this->split($amount, $policy)];
    }
}
