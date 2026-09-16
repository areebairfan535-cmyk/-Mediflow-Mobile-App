<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\ValidationException;
use App\Services\AuditService;
use App\Services\EncounterService;

/**
 * The consultation workflow (§4). One encounter is opened, filled in through
 * these endpoints, then completed.
 */
final class EncounterController extends Controller
{
    public function index(Request $request): never
    {
        $filters = $this->validateQuery($request, [
            'patient_id' => 'nullable|integer',
            'doctor_id'  => 'nullable|integer',
            'status'     => 'nullable|in:open,completed,cancelled',
        ]);

        $this->ok(['encounters' => EncounterService::for($request)->index($filters)]);
    }

    public function show(Request $request): never
    {
        $id     = $request->intParam('id');
        $record = EncounterService::for($request)->show($id);

        (new AuditService())->logPatientAccess(
            $request, (int) $record['patient_id'], 'encounter', $id,
        );

        $this->ok(['encounter' => $record]);
    }

    public function store(Request $request): never
    {
        $data = $this->validate($request, [
            'appointment_id'  => 'nullable|integer',
            'patient_id'      => 'nullable|integer',
            'doctor_id'       => 'nullable|integer',
            'type'            => 'nullable|in:outpatient,followup,emergency,teleconsult',
            'chief_complaint' => 'nullable|string|max:500',
        ]);

        // Either an appointment (which carries both) or an explicit pair.
        if (empty($data['appointment_id'])
            && (empty($data['patient_id']) || empty($data['doctor_id']))
        ) {
            throw new ValidationException([
                'appointment_id' => ['Provide an appointment_id, or both patient_id and doctor_id for a walk-in.'],
            ]);
        }

        $encounter = EncounterService::for($request)->start($data);

        (new AuditService())->log(
            $request, 'create', 'encounter', (int) $encounter['id'], null,
            ['encounter_no' => $encounter['encounter_no']],
            (int) $encounter['patient_id'],
        );

        $this->created(['encounter' => EncounterService::for($request)->show((int) $encounter['id'])]);
    }

    public function update(Request $request): never
    {
        $data = $this->validate($request, [
            'chief_complaint' => 'nullable|string|max:500',
            'symptoms'        => 'nullable|string|max:5000',
            'examination'     => 'nullable|string|max:5000',
            'bp_systolic'     => 'nullable|integer|between:40,300',
            'bp_diastolic'    => 'nullable|integer|between:20,200',
            'pulse'           => 'nullable|integer|between:20,300',
            'temperature_c'   => 'nullable|numeric|between:25,45',
            'weight_kg'       => 'nullable|numeric|between:0,500',
            'height_cm'       => 'nullable|numeric|between:0,300',
            'followup_on'     => 'nullable|date',
        ]);

        $id     = $request->intParam('id');
        $result = EncounterService::for($request)->update($id, $data);

        (new AuditService())->logUpdate(
            $request, 'encounter', $id, $result['before'], $result['after'],
            (int) $result['after']['patient_id'],
        );

        $this->ok(['encounter' => $result['after']]);
    }

    public function complete(Request $request): never
    {
        $data = $this->validate($request, ['followup_on' => 'nullable|date']);

        $id        = $request->intParam('id');
        $result    = EncounterService::for($request)->complete($id, $data['followup_on'] ?? null);
        $completed = $result['after'];

        (new AuditService())->log(
            $request, 'update', 'encounter', $id,
            ['status' => $result['before']['status']],
            ['status' => 'completed'], (int) $completed['patient_id'],
        );

        $this->ok(['encounter' => EncounterService::for($request)->show($id)]);
    }

    public function cancel(Request $request): never
    {
        $data = $this->validate($request, ['reason' => 'nullable|string|max:500']);

        $id        = $request->intParam('id');
        $result    = EncounterService::for($request)->cancel($id, $data['reason'] ?? null);
        $cancelled = $result['after'];

        (new AuditService())->log(
            $request, 'update', 'encounter', $id,
            ['status' => $result['before']['status']],
            ['status' => 'cancelled'], (int) $cancelled['patient_id'],
        );

        $this->ok(['encounter' => $cancelled]);
    }

    // ---- child records ----

    /**
     * What this clinic diagnoses, commonest first — the pick list behind §4's
     * "minimise typing". Reads nothing patient-specific: it is the wording and
     * the codes, not who they were written about.
     */
    public function commonDiagnoses(Request $request): never
    {
        $q = $this->validateQuery($request, ['search' => 'nullable|string|max:120']);

        $this->ok([
            'diagnoses' => (new \App\Repositories\EncounterRepository())
                ->forOrganization($request->organizationId())
                ->commonDiagnoses($q['search'] ?? null),
        ]);
    }

    public function addDiagnosis(Request $request): never
    {
        $data = $this->validate($request, [
            'description' => 'required|string|max:500',
            'icd10_code'  => 'nullable|string|max:16',
            'type'        => 'nullable|in:primary,secondary,provisional,differential',
            'notes'       => 'nullable|string|max:2000',
        ]);

        $id        = $request->intParam('id');
        $diagnosis = EncounterService::for($request)->addDiagnosis($id, $data);

        (new AuditService())->log(
            $request, 'create', 'diagnosis', (int) $diagnosis['id'], null, $data,
            (int) $diagnosis['patient_id'],
        );

        $this->created(['diagnosis' => $diagnosis]);
    }

    public function addProcedure(Request $request): never
    {
        $data = $this->validate($request, [
            'name'         => 'required|string|max:200',
            'cpt_code'     => 'nullable|string|max:16',
            'site'         => 'nullable|string|max:120',
            'outcome'      => 'nullable|string|max:2000',
            'service_id'   => 'nullable|integer',
            'performed_at' => 'nullable|datetime',
        ]);

        $id        = $request->intParam('id');
        $procedure = EncounterService::for($request)->addProcedure($id, $data);

        (new AuditService())->log(
            $request, 'create', 'procedure', (int) $procedure['id'], null, $data,
            (int) $procedure['patient_id'],
        );

        $this->created(['procedure' => $procedure]);
    }

    public function addNote(Request $request): never
    {
        $data = $this->validate($request, [
            'body' => 'required|string|max:20000',
            'type' => 'nullable|in:soap,progress,discharge,referral,general',
        ]);

        $id   = $request->intParam('id');
        $note = EncounterService::for($request)->addNote($id, $data);

        (new AuditService())->log(
            $request, 'create', 'clinical_note', (int) $note['id'], null,
            ['type' => $note['type']], (int) $note['patient_id'],
        );

        $this->created(['note' => $note]);
    }

    public function removeChild(Request $request): never
    {
        EncounterService::for($request)->removeChild(
            (string) $request->param('kind'),
            $request->intParam('id'),
            $request->intParam('childId'),
        );

        (new AuditService())->log(
            $request, 'delete', (string) $request->param('kind'), $request->intParam('childId'),
        );

        $this->ok(['message' => 'Removed']);
    }

    /** Body: { tests: [{name, price?}, ...], priority?, clinical_notes? } */
    public function orderLab(Request $request): never
    {
        $data = $this->validate($request, [
            'priority'       => 'nullable|in:routine,urgent,stat',
            'clinical_notes' => 'nullable|string|max:2000',
        ]);

        // The tests are the order. "Lab test ordered" with nothing named is
        // what the patient used to read, and what the lab used to guess at.
        $tests  = $request->body['tests'] ?? null;
        $errors = [];
        if (!is_array($tests) || $tests === []) {
            $errors[] = 'Name at least one test.';
        } else {
            foreach (array_values($tests) as $i => $t) {
                if (!is_array($t) || trim((string) ($t['name'] ?? '')) === '') {
                    $errors[] = 'Test ' . ($i + 1) . ' needs a name.';
                } elseif (isset($t['price']) && $t['price'] !== '' && !is_numeric($t['price'])) {
                    $errors[] = 'Test ' . ($i + 1) . ': the price must be a number.';
                }
            }
        }
        if ($errors !== []) {
            throw new ValidationException(['tests' => $errors]);
        }
        $data['tests'] = array_map(static fn (array $t): array => [
            'name'  => trim((string) $t['name']),
            'price' => isset($t['price']) && $t['price'] !== '' ? (string) $t['price'] : null,
        ], array_values($tests));

        $id    = $request->intParam('id');
        $order = EncounterService::for($request)->orderLab($id, $data);

        (new AuditService())->log(
            $request, 'create', 'lab_order', (int) $order['id'], null,
            ['order_no' => $order['order_no']], (int) $order['patient_id'],
        );

        $this->created(['lab_order' => $order]);
    }
}
