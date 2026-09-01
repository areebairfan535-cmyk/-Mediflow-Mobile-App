<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\ConflictException;
use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\ValidationException;
use App\Repositories\InsuranceRepository;
use App\Repositories\PatientRepository;
use App\Services\AuditService;
use App\Services\ClaimService;
use App\Services\EligibilityService;

/**
 * Insurance providers, patient policies, eligibility checks and claims (§8).
 */
final class InsuranceController extends Controller
{
    private function repo(): InsuranceRepository
    {
        return new InsuranceRepository();
    }

    // ---------------- providers ----------------

    public function providers(Request $request): never
    {
        $this->ok([
            'providers' => $this->repo()->providersFor((int) $request->organizationId()),
        ]);
    }

    public function storeProvider(Request $request): never
    {
        $data = $this->validate($request, [
            'name'            => 'required|string|max:200',
            'code'            => 'nullable|string|max:40',
            'contact_email'   => 'nullable|email|max:255',
            'contact_phone'   => 'nullable|string|max:32',
            'portal_url'      => 'nullable|string|max:500',
            'claim_format'    => 'nullable|string|max:60',
            'avg_settle_days' => 'nullable|integer|between:0,365',
        ]);

        $provider = $this->repo()->create($data + [
            'organization_id' => $request->organizationId(),
            'is_active'       => 1,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        (new AuditService())->log($request, 'create', 'insurance_provider', (int) $provider['id'], null, $data);

        $this->created(['provider' => $provider]);
    }

    // ---------------- policies ----------------

    public function policies(Request $request): never
    {
        $patientId = $request->intParam('patientId');

        (new PatientRepository())
            ->forOrganization($request->organizationId())
            ->findOrFail($patientId, 'Patient');

        $this->ok([
            'policies' => $this->repo()->policiesFor((int) $request->organizationId(), $patientId),
        ]);
    }

    public function storePolicy(Request $request): never
    {
        $data = $this->validate($request, [
            'insurance_provider_id' => 'required|integer',
            'policy_number'         => 'required|string|max:120',
            'member_id'             => 'nullable|string|max:120',
            'group_number'          => 'nullable|string|max:120',
            'policy_holder_name'    => 'nullable|string|max:200',
            'relation_to_patient'   => 'nullable|in:self,spouse,child,parent,other',
            'coverage_type'         => 'nullable|string|max:120',
            'coverage_amount'       => 'nullable|numeric|min:0',
            'copay_percent'         => 'nullable|numeric|between:0,100',
            'deductible'            => 'nullable|numeric|min:0',
            'valid_from'            => 'nullable|date',
            'valid_to'              => 'nullable|date',
            'is_primary'            => 'nullable|boolean',
        ]);

        $patientId = $request->intParam('patientId');
        $org       = (int) $request->organizationId();

        (new PatientRepository())->forOrganization($org)->findOrFail($patientId, 'Patient');

        if (!$this->repo()->providerUsableIn((int) $data['insurance_provider_id'], $org)) {
            throw new ValidationException(
                ['insurance_provider_id' => ['That insurer is not available to this organization.']]
            );
        }

        if (!empty($data['valid_from']) && !empty($data['valid_to'])
            && $data['valid_to'] < $data['valid_from']
        ) {
            throw new ValidationException(['valid_to' => ['Cover cannot end before it starts.']]);
        }

        $policy = $this->repo()->createPolicy($org, $data + ['patient_id' => $patientId]);

        (new AuditService())->log(
            $request, 'create', 'insurance_policy', (int) $policy['id'], null,
            ['policy_number' => $policy['policy_number']], $patientId,
        );

        $this->created(['policy' => $policy]);
    }

    public function updatePolicy(Request $request): never
    {
        $data = $this->validate($request, [
            'policy_number'       => 'nullable|string|max:120',
            'member_id'           => 'nullable|string|max:120',
            'group_number'        => 'nullable|string|max:120',
            'policy_holder_name'  => 'nullable|string|max:200',
            'relation_to_patient' => 'nullable|in:self,spouse,child,parent,other',
            'coverage_type'       => 'nullable|string|max:120',
            'coverage_amount'     => 'nullable|numeric|min:0',
            'copay_percent'       => 'nullable|numeric|between:0,100',
            'deductible'          => 'nullable|numeric|min:0',
            'valid_from'          => 'nullable|date',
            'valid_to'            => 'nullable|date',
            'status'              => 'nullable|in:active,expired,suspended',
        ]);

        $org      = (int) $request->organizationId();
        $policyId = $request->intParam('id');
        $before   = $this->repo()->findPolicy($org, $policyId);

        if ($before === null) {
            throw new NotFoundException('Policy not found');
        }

        $after = $this->repo()->updatePolicy($org, $policyId, $data);

        (new AuditService())->logUpdate(
            $request, 'insurance_policy', $policyId, $before, $after, (int) $before['patient_id'],
        );

        $this->ok(['policy' => $after]);
    }

    // ---------------- eligibility (§25 Phase 5) ----------------

    /** What will the insurer cover on this invoice? Changes nothing. */
    public function checkInvoice(Request $request): never
    {
        $q = $this->validateQuery($request, ['policy_id' => 'nullable|integer']);

        $this->ok(EligibilityService::for($request)->quoteInvoice(
            $request->intParam('id'),
            isset($q['policy_id']) ? (int) $q['policy_id'] : null,
        ));
    }

    /** Pre-treatment check: "if it costs X, what will they owe?" */
    public function checkAmount(Request $request): never
    {
        $data = $this->validate($request, [
            'patient_id' => 'required|integer',
            'amount'     => 'required|numeric|min:0',
        ]);

        $this->ok(EligibilityService::for($request)->quoteAmount(
            (int) $data['patient_id'],
            (string) $data['amount'],
        ));
    }

    // ---------------- claims (§8) ----------------

    public function claims(Request $request): never
    {
        $filters = $this->validateQuery($request, [
            'status'      => 'nullable|in:draft,submitted,processing,approved,partially_approved,rejected,resubmission,paid',
            'patient_id'  => 'nullable|integer',
            'provider_id' => 'nullable|integer',
            'search'      => 'nullable|string|max:120',
            'open'        => 'nullable|boolean',
        ]);

        [$page, $perPage] = $this->pagination($request);

        $result = ClaimService::for($request)->search($filters, $page, $perPage);

        $this->ok($result['data'], $result['meta']);
    }

    public function showClaim(Request $request): never
    {
        $claim = ClaimService::for($request)->show($request->intParam('id'));

        (new AuditService())->logPatientAccess(
            $request, (int) $claim['patient_id'], 'claim', (int) $claim['id'],
        );

        $this->ok(['claim' => $claim]);
    }

    public function createClaim(Request $request): never
    {
        $data = $this->validate($request, [
            'invoice_id' => 'required|integer',
            'policy_id'  => 'nullable|integer',
        ]);

        $result = ClaimService::for($request)->createFromInvoice(
            (int) $data['invoice_id'],
            isset($data['policy_id']) ? (int) $data['policy_id'] : null,
        );

        (new AuditService())->log(
            $request, 'create', 'claim', (int) $result['claim']['id'], null,
            ['claim_no' => $result['claim']['claim_no'],
             'claimed'  => $result['claim']['claimed_amount']],
            (int) $result['claim']['patient_id'],
        );

        // Warnings ride along: a biller must see what would likely get rejected.
        $this->created(['claim' => $result['claim'], 'warnings' => $result['warnings']]);
    }

    public function submitClaim(Request $request): never
    {
        $data = $this->validate($request, [
            'external_claim_no' => 'nullable|string|max:120',
        ]);

        $claim = ClaimService::for($request)
            ->submit($request->intParam('id'), $data['external_claim_no'] ?? null);

        (new AuditService())->log(
            $request, 'update', 'claim', (int) $claim['id'], null,
            ['status' => 'submitted', 'claimed' => $claim['claimed_amount']],
            (int) $claim['patient_id'],
        );

        $this->ok(['claim' => $claim]);
    }

    public function processingClaim(Request $request): never
    {
        $this->ok(['claim' => ClaimService::for($request)->markProcessing($request->intParam('id'))]);
    }

    public function decideClaim(Request $request): never
    {
        $data = $this->validate($request, [
            'approved_amount'  => 'required|numeric|min:0',
            'rejection_code'   => 'nullable|string|max:60',
            'rejection_reason' => 'nullable|string|max:1000',
        ]);

        if (isset($request->body['line_decisions'])) {
            $data['line_decisions'] = $request->body['line_decisions'];
        }

        $claim = ClaimService::for($request)->recordDecision($request->intParam('id'), $data);

        (new AuditService())->log(
            $request, 'update', 'claim', (int) $claim['id'], null,
            [
                'status'   => $claim['status'],
                'approved' => $claim['approved_amount'],
                'reason'   => $claim['rejection_reason'],
            ],
            (int) $claim['patient_id'],
        );

        $this->ok(['claim' => $claim]);
    }

    public function payClaim(Request $request): never
    {
        $data = $this->validate($request, [
            'amount'    => 'nullable|numeric|min:0',
            'reference' => 'nullable|string|max:191',
        ]);

        $claim = ClaimService::for($request)->markPaid(
            $request->intParam('id'),
            isset($data['amount']) ? (string) $data['amount'] : null,
            $data['reference'] ?? null,
        );

        (new AuditService())->log(
            $request, 'update', 'claim', (int) $claim['id'], null,
            ['status' => 'paid', 'paid' => $claim['paid_amount']],
            (int) $claim['patient_id'],
        );

        $this->ok(['claim' => $claim]);
    }

    public function resubmitClaim(Request $request): never
    {
        $claim = ClaimService::for($request)->resubmit($request->intParam('id'));

        (new AuditService())->log(
            $request, 'create', 'claim', (int) $claim['id'], null,
            ['resubmission_of' => $request->intParam('id'), 'claim_no' => $claim['claim_no']],
            (int) $claim['patient_id'],
        );

        $this->created(['claim' => $claim]);
    }

    public function deleteClaim(Request $request): never
    {
        ClaimService::for($request)->deleteDraft($request->intParam('id'));

        (new AuditService())->log($request, 'delete', 'claim', $request->intParam('id'));

        $this->ok(['message' => 'Draft claim deleted']);
    }

    /** §8 analytics: what is outstanding, and why claims get rejected. */
    public function pipeline(Request $request): never
    {
        $this->ok(ClaimService::for($request)->pipeline());
    }
}
