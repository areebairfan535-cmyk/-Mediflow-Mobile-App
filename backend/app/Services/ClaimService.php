<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ConflictException;
use App\Core\Database;
use App\Core\NotFoundException;
use App\Core\Service;
use App\Core\ValidationException;
use App\Repositories\ClaimRepository;
use App\Repositories\InsuranceRepository;
use App\Repositories\InvoiceRepository;
use App\Services\Billing\Money;

/**
 * Claim lifecycle (§8).
 *
 *   draft ──submit──► submitted ──► processing ──┬─► approved ──paid──► paid
 *     │                                          ├─► partially_approved ──► paid
 *     │                                          └─► rejected ──► resubmission
 *     └── deleted while still a draft
 *
 * Two things make this more than CRUD:
 *
 *  1. **Coverage is reserved on submission, not on approval.** The moment a
 *     claim goes out, that money is spoken for; letting a second claim spend
 *     the same ceiling because the first has not been decided yet is how a
 *     clinic ends up over-claimed and short. A rejection releases it again.
 *
 *  2. **An insurer's payment settles the invoice through the normal ledger.**
 *     Marking a claim paid records a `payments` row with method='insurance',
 *     so the invoice's paid_total and status derive from the same source as
 *     every cash payment. There is no second, parallel notion of "paid".
 */
final class ClaimService extends Service
{
    /** Which statuses each status may move to (§8's set). */
    private const TRANSITIONS = [
        'draft'              => ['submitted'],
        'submitted'          => ['processing', 'approved', 'partially_approved', 'rejected'],
        'processing'         => ['approved', 'partially_approved', 'rejected'],
        'approved'           => ['paid'],
        'partially_approved' => ['paid'],
        'rejected'           => ['resubmission'],
        'resubmission'       => ['submitted'],
        'paid'               => [],
    ];

    /** Statuses where the insurer still owes an answer and cover stays reserved. */
    private const RESERVES_COVER = ['submitted', 'processing', 'approved', 'partially_approved', 'paid'];

    private function claims(): ClaimRepository
    {
        return (new ClaimRepository())->forOrganization($this->requireOrganization());
    }

    private function invoices(): InvoiceRepository
    {
        return (new InvoiceRepository())->forOrganization($this->requireOrganization());
    }

    private function insurance(): InsuranceRepository
    {
        return new InsuranceRepository();
    }

    private function eligibility(): EligibilityService
    {
        return new EligibilityService($this->organizationId, $this->actorId);
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function show(int $id): array
    {
        $claim = $this->claims()->findDetailed($id);
        if ($claim === null) {
            throw new NotFoundException('Claim not found');
        }
        return $claim;
    }

    /** @return array{data: list<array<string,mixed>>, meta: array<string,int>} */
    public function search(array $filters, int $page, int $perPage): array
    {
        return $this->claims()->search($filters, $page, $perPage);
    }

    // ---------------------------------------------------------------
    // Create
    // ---------------------------------------------------------------

    /**
     * Build a draft claim from an issued invoice.
     *
     * Lines come from the invoice, each carrying its billing code and a
     * diagnosis from the encounter — §8's "treatment → billing codes →
     * insurance rules → claim". A line with no diagnosis is still claimed, but
     * reported back so a biller can fix it before submission.
     *
     * @return array{claim: array<string,mixed>, warnings: list<string>}
     */
    public function createFromInvoice(int $invoiceId, ?int $policyId = null): array
    {
        $org     = $this->requireOrganization();
        $invoice = $this->invoices()->findOrFail($invoiceId, 'Invoice');

        if ($invoice['status'] === 'draft') {
            throw new ConflictException('Issue the invoice before claiming against it.');
        }
        if (in_array($invoice['status'], ['cancelled', 'refunded'], true)) {
            throw new ConflictException("A {$invoice['status']} invoice cannot be claimed.");
        }
        if ($this->claims()->forInvoice($invoiceId) !== null) {
            throw new ConflictException(
                'This invoice already has a live claim. Reject or resubmit that one instead.'
            );
        }

        $date   = $invoice['issue_date'] ?? gmdate('Y-m-d');
        $policy = $policyId !== null
            ? $this->insurance()->findPolicy($org, $policyId)
            : $this->eligibility()->activePolicy((int) $invoice['patient_id'], $date);

        if ($policy === null) {
            throw new ConflictException('This patient has no active insurance policy on file.');
        }
        if ((int) $policy['patient_id'] !== (int) $invoice['patient_id']) {
            throw new ValidationException(
                ['policy_id' => ['That policy belongs to a different patient.']]
            );
        }

        $check = $this->eligibility()->checkPolicy($policy, $date);
        if (!$check['eligible']) {
            throw new ConflictException(implode(' ', $check['reasons']));
        }

        $coverage = $this->eligibility()->split((string) $invoice['grand_total'], $policy, $date);

        if (Money::isZero($coverage['insurance_payable'])) {
            throw new ConflictException(
                'This policy would pay nothing on this invoice — the patient owes the full amount.'
            );
        }

        // Diagnoses from the encounter justify the charges.
        $diagnosisCode = null;
        if (!empty($invoice['encounter_id'])) {
            $primary = Database::selectOne(
                'SELECT icd10_code FROM diagnoses
                  WHERE organization_id = :org AND encounter_id = :eid
                    AND icd10_code IS NOT NULL
                  ORDER BY FIELD(type, \'primary\',\'secondary\',\'provisional\',\'differential\')
                  LIMIT 1',
                ['org' => $org, 'eid' => (int) $invoice['encounter_id']],
            );
            $diagnosisCode = $primary['icd10_code'] ?? null;
        }

        $warnings = [];
        if ($diagnosisCode === null) {
            $warnings[] = 'No ICD-10 diagnosis on the linked visit — insurers commonly '
                        . 'reject claims without one.';
        }
        if (!Money::isZero($coverage['capped_by_ceiling'])) {
            $warnings[] = sprintf(
                'The policy ceiling caps this claim by %s %s; the patient carries that.',
                $invoice['currency_code'],
                $coverage['capped_by_ceiling'],
            );
        }
        if (!Money::isZero($coverage['deductible_applied'])) {
            $warnings[] = sprintf(
                'A deductible of %s %s was applied.',
                $invoice['currency_code'],
                $coverage['deductible_applied'],
            );
        }

        $lines = array_map(
            static fn(array $item): array => [
                'invoice_item_id' => (int) $item['id'],
                'billing_code'    => $item['service_code'],
                'diagnosis_code'  => $diagnosisCode,
                'description'     => $item['description'],
                'quantity'        => $item['quantity'],
                'claimed_amount'  => $item['line_total'],
            ],
            $this->invoices()->items($invoiceId),
        );

        $repo = $this->claims();

        $claim = $this->transaction(function () use ($repo, $invoice, $policy, $coverage, $lines): array {
            $created = $repo->create($this->stampCreate([
                'patient_id'             => (int) $invoice['patient_id'],
                'invoice_id'             => (int) $invoice['id'],
                'encounter_id'           => $invoice['encounter_id'] ?? null,
                'insurance_policy_id'    => (int) $policy['id'],
                'claim_no'               => $repo->nextClaimNo(),
                'status'                 => 'draft',
                'currency_code'          => $invoice['currency_code'],
                'claimed_amount'         => $coverage['insurance_payable'],
                'patient_responsibility' => $coverage['patient_responsibility'],
                'submission_count'       => 0,
            ]));

            $repo->replaceItems((int) $created['id'], $lines);

            // Record the split on the invoice so the clinic can see, at a
            // glance, who owes what without opening the claim.
            $this->invoices()->update((int) $invoice['id'], [
                'insurance_payable' => $coverage['insurance_payable'],
                'patient_payable'   => $coverage['patient_responsibility'],
            ]);

            return $created;
        });

        return ['claim' => $this->show((int) $claim['id']), 'warnings' => $warnings];
    }

    // ---------------------------------------------------------------
    // Lifecycle
    // ---------------------------------------------------------------

    /** Send the claim to the insurer. Reserves the coverage. */
    public function submit(int $id, ?string $externalClaimNo = null): array
    {
        $repo  = $this->claims();
        $claim = $repo->findOrFail($id, 'Claim');

        $this->assertTransition($claim, 'submitted');

        if ($repo->items($id) === []) {
            throw new ConflictException('A claim needs at least one line before submission.');
        }

        return $this->transaction(function () use ($repo, $id, $claim, $externalClaimNo): array {
            $repo->update($id, $this->stampUpdate([
                'status'            => 'submitted',
                'external_claim_no' => $externalClaimNo,
                'submitted_at'      => now(),
                'submission_count'  => (int) $claim['submission_count'] + 1,
            ]));

            // Reserve the cover now — see the class comment.
            $this->insurance()->adjustCoverageUsed(
                $this->requireOrganization(),
                (int) $claim['insurance_policy_id'],
                (string) $claim['claimed_amount'],
            );

            return $this->show($id);
        });
    }

    public function markProcessing(int $id): array
    {
        $claim = $this->claims()->findOrFail($id, 'Claim');
        $this->assertTransition($claim, 'processing');

        $this->claims()->update($id, $this->stampUpdate(['status' => 'processing']));

        return $this->show($id);
    }

    /**
     * Record the insurer's decision.
     *
     * `approved_amount` decides which of §8's three outcomes this is:
     *   full   -> approved
     *   partial-> partially_approved   (difference falls to the patient)
     *   zero   -> rejected             (reserved cover is released)
     *
     * @param array<string,mixed> $data approved_amount, rejection_code?,
     *                            rejection_reason?, line_decisions?
     */
    public function recordDecision(int $id, array $data): array
    {
        $repo  = $this->claims();
        $claim = $repo->findOrFail($id, 'Claim');

        if (!in_array($claim['status'], ['submitted', 'processing'], true)) {
            throw new ConflictException(
                "A decision can only be recorded on a submitted claim — this one is {$claim['status']}."
            );
        }

        $claimed  = (string) $claim['claimed_amount'];
        $approved = Money::round($data['approved_amount'] ?? 0);

        if (Money::isNegative($approved)) {
            throw new ValidationException(['approved_amount' => ['Cannot be negative.']]);
        }
        if (Money::greaterThan($approved, $claimed)) {
            throw new ValidationException([
                'approved_amount' => ["An insurer cannot approve more than the $claimed claimed."],
            ]);
        }

        $status = Money::isZero($approved) ? 'rejected'
            : (Money::compare($approved, $claimed) === 0 ? 'approved' : 'partially_approved');

        if ($status !== 'approved'
            && empty($data['rejection_reason'])
        ) {
            // §8 requires the reason to be stored — it drives resubmission and
            // the analytics that tell a clinic why it loses money.
            throw new ValidationException([
                'rejection_reason' => ['A reason is required when the insurer does not pay in full.'],
            ]);
        }

        // Whatever the insurer will not pay lands back on the patient.
        $shortfall     = Money::subtract($claimed, $approved);
        $patientOwes   = Money::round(Money::add((string) $claim['patient_responsibility'], $shortfall));

        return $this->transaction(function () use (
            $repo, $id, $claim, $approved, $status, $data, $shortfall, $patientOwes
        ): array {
            $repo->update($id, $this->stampUpdate([
                'status'                 => $status,
                'approved_amount'        => $approved,
                'patient_responsibility' => $patientOwes,
                'rejection_code'         => $data['rejection_code']   ?? null,
                'rejection_reason'       => $data['rejection_reason'] ?? null,
                'decided_at'             => now(),
            ]));

            // Release the cover the insurer refused, so it is available again.
            if (!Money::isZero($shortfall)) {
                $this->insurance()->adjustCoverageUsed(
                    $this->requireOrganization(),
                    (int) $claim['insurance_policy_id'],
                    '-' . $shortfall,
                );
            }

            if (isset($data['line_decisions']) && is_array($data['line_decisions'])) {
                $this->applyLineDecisions($id, $data['line_decisions']);
            }

            // Keep the invoice's split in step with the decision.
            $this->invoices()->update((int) $claim['invoice_id'], [
                'insurance_payable' => $approved,
                'patient_payable'   => $patientOwes,
            ]);

            $this->notifyPatient($claim, $status);

            return $this->show($id);
        });
    }

    /**
     * The insurer has paid. Records a payment against the invoice through the
     * normal ledger, so the invoice status derives from one source of truth.
     */
    public function markPaid(int $id, ?string $amount = null, ?string $reference = null): array
    {
        $repo  = $this->claims();
        $claim = $repo->findOrFail($id, 'Claim');

        $this->assertTransition($claim, 'paid');

        $paid = Money::round($amount ?? $claim['approved_amount']);

        if (Money::isZero($paid)) {
            throw new ConflictException('Nothing was approved on this claim, so nothing can be paid.');
        }
        if (Money::greaterThan($paid, (string) $claim['approved_amount'])) {
            throw new ValidationException([
                'amount' => ['More than the insurer approved on this claim.'],
            ]);
        }

        return $this->transaction(function () use ($repo, $id, $claim, $paid, $reference): array {
            $repo->update($id, $this->stampUpdate([
                'status'      => 'paid',
                'paid_amount' => $paid,
                'paid_at'     => now(),
            ]));

            // The insurer's money is a payment like any other.
            (new PaymentService($this->organizationId, $this->actorId))->record(
                (int) $claim['invoice_id'],
                [
                    'amount'      => $paid,
                    'method'      => 'insurance',
                    'gateway'     => 'insurance',
                    'gateway_ref' => $reference ?? $claim['external_claim_no'] ?? $claim['claim_no'],
                    'notes'       => 'Settlement of claim ' . $claim['claim_no'],
                ],
            );

            return $this->show($id);
        });
    }

    /**
     * Open a fresh claim to replace a rejected one (§8 "Resubmission").
     *
     * A new row rather than a status flip: the rejected claim and its reason
     * must survive for the analytics §8 asks for.
     */
    public function resubmit(int $id): array
    {
        $repo     = $this->claims();
        $original = $repo->findOrFail($id, 'Claim');

        if ($original['status'] !== 'rejected') {
            throw new ConflictException(
                "Only a rejected claim can be resubmitted — this one is {$original['status']}."
            );
        }

        $policy = $this->insurance()->findPolicy(
            $this->requireOrganization(),
            (int) $original['insurance_policy_id'],
        );

        $check = $this->eligibility()->checkPolicy($policy ?? []);
        if (!$check['eligible']) {
            throw new ConflictException(
                'The policy can no longer be claimed against: ' . implode(' ', $check['reasons'])
            );
        }

        $items = $repo->items($id);

        $fresh = $this->transaction(function () use ($repo, $original, $items, $id): array {
            $created = $repo->create($this->stampCreate([
                'patient_id'             => (int) $original['patient_id'],
                'invoice_id'             => (int) $original['invoice_id'],
                'encounter_id'           => $original['encounter_id'],
                'insurance_policy_id'    => (int) $original['insurance_policy_id'],
                'claim_no'               => $repo->nextClaimNo(),
                'status'                 => 'resubmission',
                'currency_code'          => $original['currency_code'],
                'claimed_amount'         => $original['claimed_amount'],
                'patient_responsibility' => $original['patient_responsibility'],
                'resubmission_of'        => $id,
                'submission_count'       => 0,
            ]));

            $repo->replaceItems((int) $created['id'], array_map(
                static fn(array $item): array => [
                    'invoice_item_id' => $item['invoice_item_id'],
                    'billing_code'    => $item['billing_code'],
                    'diagnosis_code'  => $item['diagnosis_code'],
                    'description'     => $item['description'],
                    'quantity'        => $item['quantity'],
                    'claimed_amount'  => $item['claimed_amount'],
                ],
                $items,
            ));

            return $created;
        });

        return $this->show((int) $fresh['id']);
    }

    public function deleteDraft(int $id): void
    {
        $claim = $this->claims()->findOrFail($id, 'Claim');

        if ($claim['status'] !== 'draft') {
            throw new ConflictException(
                "Only a draft claim can be deleted — this one is {$claim['status']}."
            );
        }

        $this->claims()->delete($id);
    }

    // ---------------------------------------------------------------
    // Analytics (§8)
    // ---------------------------------------------------------------

    /** @return array<string,mixed> */
    public function pipeline(): array
    {
        $repo     = $this->claims();
        $byStatus = $repo->pipeline();

        $sum = static function (array $statuses) use ($byStatus): string {
            $total = '0';
            foreach ($statuses as $status) {
                $total = Money::add($total, $byStatus[$status]['claimed'] ?? 0);
            }
            return Money::round($total);
        };

        return [
            'by_status'  => $byStatus,
            'outstanding' => $sum(['submitted', 'processing', 'resubmission']),
            'approved'    => Money::round($byStatus['approved']['approved'] ?? 0),
            'settled'     => Money::round($byStatus['paid']['paid'] ?? 0),
            'rejected'    => Money::round($byStatus['rejected']['claimed'] ?? 0),
            'rejections'  => $repo->rejectionAnalysis(),
        ];
    }

    // ---------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------

    /** @param array<string,mixed> $claim */
    private function assertTransition(array $claim, string $to): void
    {
        $from    = (string) $claim['status'];
        $allowed = self::TRANSITIONS[$from] ?? [];

        if (!in_array($to, $allowed, true)) {
            throw new ConflictException(
                $allowed === []
                    ? "This claim is $from and cannot change."
                    : "Cannot go from $from to $to. Allowed: " . implode(', ', $allowed) . '.'
            );
        }
    }

    /** @param list<array<string,mixed>> $decisions */
    private function applyLineDecisions(int $claimId, array $decisions): void
    {
        foreach ($decisions as $decision) {
            if (empty($decision['id'])) {
                continue;
            }
            Database::statement(
                'UPDATE claim_items
                    SET approved_amount  = :amount,
                        status           = :status,
                        rejection_reason = :reason,
                        updated_at       = :now
                  WHERE organization_id = :org AND claim_id = :cid AND id = :id',
                [
                    'amount' => Money::round($decision['approved_amount'] ?? 0),
                    'status' => $decision['status'] ?? 'claimed',
                    'reason' => $decision['rejection_reason'] ?? null,
                    'now'    => now(),
                    'org'    => $this->requireOrganization(),
                    'cid'    => $claimId,
                    'id'     => (int) $decision['id'],
                ],
            );
        }
    }

    /** @param array<string,mixed> $claim */
    private function notifyPatient(array $claim, string $status): void
    {
        try {
            (new NotificationService($this->organizationId, $this->actorId))->notifyPatient(
                (int) $claim['patient_id'],
                'claim.updated',
                [
                    'claim_no'     => $claim['claim_no'],
                    'status'       => str_replace('_', ' ', $status),
                    'subject_type' => 'claim',
                    'subject_id'   => (int) $claim['id'],
                ],
            );
        } catch (\Throwable $e) {
            error_log('[notify] claim notification failed: ' . $e->getMessage());
        }
    }
}
