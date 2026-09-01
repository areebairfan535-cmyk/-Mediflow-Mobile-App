<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Services\Ai\AiProviders;
use App\Services\AiAssistantService;
use App\Services\AuditService;

/**
 * The AI module's HTTP surface (§9).
 *
 * Every route here is optional by design: with no provider configured they
 * return 503 with a clear message and the rest of the system is unaffected.
 * §26 puts AI outside the MVP, so "not configured" is a normal state.
 */
final class AiController extends Controller
{
    /** Public within the tenant: the UI needs it to decide whether to show AI buttons. */
    public function status(Request $request): never
    {
        $this->ok(AiProviders::status());
    }

    // ---------------- documentation assistant ----------------

    public function draftNote(Request $request): never
    {
        $data = $this->validate($request, [
            'shorthand' => 'required|string|min:3|max:5000',
        ]);

        $result = AiAssistantService::for($request)
            ->draftClinicalNote($request->intParam('id'), (string) $data['shorthand']);

        (new AuditService())->log(
            $request, 'create', 'clinical_note', (int) $result['note']['id'], null,
            ['is_ai_drafted' => 1, 'encounter_id' => $request->intParam('id')],
            (int) $result['note']['patient_id'],
        );

        $this->created([
            'note'  => $result['note'],
            'draft' => $result['draft'],
            'message' => 'Draft saved. It is not part of the record until you approve it.',
        ]);
    }

    public function approveNote(Request $request): never
    {
        $data = $this->validate($request, [
            'body' => 'nullable|string|max:20000',
        ]);

        $note = AiAssistantService::for($request)
            ->approveNote($request->intParam('id'), $data['body'] ?? null);

        // The approval is the auditable act — who took responsibility for it.
        (new AuditService())->log(
            $request, 'update', 'clinical_note', (int) $note['id'], null,
            ['approved' => true, 'edited' => isset($data['body'])],
            (int) $note['patient_id'],
        );

        $this->ok(['note' => $note]);
    }

    public function discardNote(Request $request): never
    {
        AiAssistantService::for($request)->discardNote($request->intParam('id'));

        (new AuditService())->log($request, 'delete', 'clinical_note', $request->intParam('id'));

        $this->ok(['message' => 'Draft discarded.']);
    }

    // ---------------- billing assistant ----------------

    public function suggestBilling(Request $request): never
    {
        $result = AiAssistantService::for($request)->suggestBilling($request->intParam('id'));

        (new AuditService())->log(
            $request, 'view', 'ai_billing_suggestion', $request->intParam('id'), null,
            ['suggestions' => count($result['suggestions'])],
        );

        $this->ok($result);
    }

    // ---------------- patient summary (§25) ----------------

    public function summarisePatient(Request $request): never
    {
        $id = $request->intParam('id');

        $result = AiAssistantService::for($request)->summarisePatient($id);

        (new AuditService())->log(
            $request, 'view', 'ai_patient_summary', $id, null, null, $id,
        );

        $this->ok($result);
    }

    // ---------------- search (§25) ----------------

    /**
     * Filed with the AI routes because §25 lists it under the AI phase, and
     * answered by SQL because a receptionist typing a phone number needs the
     * same answer in the same 40ms every time. No provider is involved, so
     * this one works whether or not AI is configured.
     */
    public function search(Request $request): never
    {
        $q = $this->validateQuery($request, [
            'q'     => 'required|string|min:2|max:120',
            'limit' => 'nullable|integer|between:1,50',
        ]);

        $this->ok(AiAssistantService::for($request)->search(
            (string) $q['q'],
            (int) ($q['limit'] ?? 20),
        ));
    }

    // ---------------- claim assistant ----------------

    public function reviewClaim(Request $request): never
    {
        $result = AiAssistantService::for($request)->reviewClaim($request->intParam('id'));

        (new AuditService())->log(
            $request, 'update', 'claim', $request->intParam('id'), null,
            ['ai_risk_score' => $result['risk_score']],
        );

        $this->ok($result);
    }
}
