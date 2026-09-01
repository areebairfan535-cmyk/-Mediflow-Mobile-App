<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ConflictException;
use App\Core\Database;
use App\Core\NotFoundException;
use App\Core\Service;
use App\Core\ValidationException;
use App\Repositories\ClaimRepository;
use App\Repositories\EncounterRepository;
use App\Repositories\InvoiceRepository;
use App\Repositories\ServiceRepository;
use App\Services\Ai\AiProviders;
use App\Services\Billing\Money;

/**
 * The three assistants of §9.
 *
 * ---------------------------------------------------------------------------
 * The rule this class exists to enforce:
 *
 *   "AI should assist clinicians and billing staff; it should not autonomously
 *    make clinical diagnoses or irreversible billing decisions."      — §9
 * ---------------------------------------------------------------------------
 *
 * So nothing here writes a final record. Concretely:
 *
 *   - draftClinicalNote() saves a note with is_ai_drafted = 1 and
 *     approved_by = NULL. It is not part of the chart until a clinician
 *     approves it.
 *   - suggestBilling() returns suggestions. It does not touch the invoice;
 *     a biller accepts lines, and accepted lines are flagged is_ai_suggested.
 *   - reviewClaim() returns a risk score and a missing-items list. It never
 *     submits, never changes a status.
 *
 * The schema anticipated this: `clinical_notes.is_ai_drafted` / `approved_by`,
 * `invoice_items.is_ai_suggested`, and `claims.ai_risk_score` /
 * `ai_missing_items` were all created in Phase 1.
 */
final class AiAssistantService extends Service
{
    private function encounters(): EncounterRepository
    {
        return (new EncounterRepository())->forOrganization($this->requireOrganization());
    }

    private function invoices(): InvoiceRepository
    {
        return (new InvoiceRepository())->forOrganization($this->requireOrganization());
    }

    private function claims(): ClaimRepository
    {
        return (new ClaimRepository())->forOrganization($this->requireOrganization());
    }

    private function catalogue(): ServiceRepository
    {
        return (new ServiceRepository())->forOrganization($this->requireOrganization());
    }

    // ===============================================================
    // 1. AI Documentation Assistant (§9)
    // ===============================================================

    /**
     * Turn a clinician's shorthand into a structured clinical-note draft.
     *
     * Saved immediately — as a DRAFT. Saving it means the clinician does not
     * lose the work if they navigate away; `approved_by = NULL` means it is
     * not yet part of the record.
     *
     * @return array{note: array<string,mixed>, draft: array<string,mixed>}
     */
    public function draftClinicalNote(int $encounterId, string $shorthand): array
    {
        $this->plan()->assertWithin('ai_calls');               // §22 AI usage allowance

        $record = $this->encounters()->fullRecord($encounterId);
        if ($record === null) {
            throw new NotFoundException('Encounter not found');
        }
        if ($record['status'] !== 'open') {
            throw new ConflictException(
                'Notes can only be drafted on an open consultation.'
            );
        }

        $system = <<<'TXT'
You are a clinical documentation assistant in a medical records system.

Turn the clinician's shorthand into a structured clinical note in SOAP form.

Rules you must not break:
- Use ONLY what the clinician wrote and the visit context given. Never add a
  symptom, finding, measurement, diagnosis or medicine that is not there.
- You are not diagnosing. If the clinician recorded an impression, restate it
  as theirs. Do not add one of your own.
- Anything you are unsure of goes in "uncertainties", not into the note.
- Expand obvious clinical abbreviations, but if an abbreviation is ambiguous,
  leave it as written and list it under "uncertainties".

Reply with a single JSON object and nothing else:
{
  "subjective": "what the patient reports, in prose",
  "objective": "examination findings and vitals, in prose",
  "assessment": "the clinician's impression, restated - not your own",
  "plan": "treatment, investigations, follow-up",
  "uncertainties": ["anything ambiguous or missing that the clinician should confirm"]
}
TXT;

        $prompt = "Visit context:\n" . $this->encounterContext($record)
                . "\n\nClinician's shorthand:\n" . trim($shorthand);

        $draft = AiProviders::resolve()->completeJson(
            $system, $prompt, ['max_tokens' => 2000, 'task' => 'note'],
        );

        $this->plan()->recordUsage('ai_calls');

        $body = $this->renderSoap($draft);

        $note = $this->encounters()->addNote([
            'encounter_id'  => $encounterId,
            'patient_id'    => (int) $record['patient_id'],
            'type'          => 'soap',
            'body'          => $body,
            // The two fields that keep this out of the record until approved.
            'is_ai_drafted' => 1,
            'approved_by'   => null,
            'created_by'    => $this->actorId,
        ]);

        return ['note' => $note, 'draft' => $draft];
    }

    /**
     * A clinician approves the draft — the moment it becomes part of the chart.
     *
     * Editing is allowed at approval, because a clinician correcting the
     * machine is the point of the whole design.
     */
    public function approveNote(int $noteId, ?string $editedBody = null): array
    {
        $org  = $this->requireOrganization();
        $note = Database::selectOne(
            'SELECT * FROM clinical_notes WHERE organization_id = :org AND id = :id',
            ['org' => $org, 'id' => $noteId],
        );

        if ($note === null) {
            throw new NotFoundException('Note not found');
        }
        if ($note['approved_by'] !== null) {
            throw new ConflictException('This note has already been approved.');
        }

        Database::statement(
            'UPDATE clinical_notes
                SET body = :body, approved_by = :by, approved_at = :now, updated_at = :now
              WHERE organization_id = :org AND id = :id',
            [
                'body' => $editedBody ?? $note['body'],
                'by'   => $this->actorId,
                'now'  => now(),
                'org'  => $org,
                'id'   => $noteId,
            ],
        );

        return Database::selectOne(
            'SELECT * FROM clinical_notes WHERE id = :id',
            ['id' => $noteId],
        ) ?? [];
    }

    public function discardNote(int $noteId): void
    {
        $org  = $this->requireOrganization();
        $note = Database::selectOne(
            'SELECT * FROM clinical_notes WHERE organization_id = :org AND id = :id',
            ['org' => $org, 'id' => $noteId],
        );

        if ($note === null) {
            throw new NotFoundException('Note not found');
        }
        if ($note['approved_by'] !== null) {
            throw new ConflictException(
                'An approved note is part of the record and cannot be discarded.'
            );
        }

        Database::statement(
            'DELETE FROM clinical_notes WHERE organization_id = :org AND id = :id',
            ['org' => $org, 'id' => $noteId],
        );
    }

    // ===============================================================
    // 2. AI Billing Assistant (§9, §28)
    // ===============================================================

    /**
     * Suggest billable services for a completed consultation.
     *
     * Returns suggestions only. The invoice is untouched — §9 calls billing a
     * decision that must not be made autonomously, and §28 puts a human
     * approval step in the middle of the revenue-cycle flow for the same
     * reason.
     *
     * Suggestions are matched back to real catalogue codes before returning:
     * a code the clinic does not offer is dropped rather than shown, because a
     * biller cannot act on it anyway.
     *
     * @return array<string,mixed>
     */
    public function suggestBilling(int $encounterId): array
    {
        $this->plan()->assertWithin('ai_calls');               // §22

        $record = $this->encounters()->fullRecord($encounterId);
        if ($record === null) {
            throw new NotFoundException('Encounter not found');
        }

        $existing = $this->invoices()->forEncounter($encounterId);

        $catalogue = $this->catalogue()->catalogue(null, null, null);
        $lines     = array_map(
            static fn(array $s): string => sprintf(
                '- %s | %s | %s',
                $s['code'],
                $s['name'],
                $s['price'] !== null ? $s['price'] . ' ' . $s['currency_code'] : 'no price set',
            ),
            $catalogue,
        );

        $system = <<<'TXT'
You are a medical billing assistant. Suggest which services from the clinic's
catalogue should be billed for a consultation.

Rules you must not break:
- Suggest ONLY codes that appear in the catalogue given to you. Never invent a
  code, a service name or a price.
- Suggest a service only if the visit record actually supports it. A procedure
  that was not recorded was not performed.
- You are not deciding anything. A human will review every line.
- Say what evidence in the record supports each suggestion, quoting it.

Reply with a single JSON object and nothing else:
{
  "suggestions": [
    {
      "code": "exact code from the catalogue",
      "quantity": 1,
      "confidence": "high" | "medium" | "low",
      "evidence": "what in the record supports this"
    }
  ],
  "notes": ["anything the biller should check by hand"]
}
TXT;

        $prompt = "Clinic catalogue (code | name | price):\n" . implode("\n", $lines)
                . "\n\nVisit record:\n" . $this->encounterContext($record);

        $result = AiProviders::resolve()->completeJson(
            $system, $prompt, ['max_tokens' => 2000, 'task' => 'billing'],
        );

        $this->plan()->recordUsage('ai_calls');

        // Keep only codes the clinic actually offers, and attach the real price
        // from the catalogue — never a price the model produced.
        $byCode      = [];
        foreach ($catalogue as $service) {
            $byCode[strtoupper((string) $service['code'])] = $service;
        }

        $suggestions = [];
        $dropped     = [];

        foreach ($result['suggestions'] ?? [] as $suggestion) {
            $code    = strtoupper(trim((string) ($suggestion['code'] ?? '')));
            $service = $byCode[$code] ?? null;

            if ($service === null) {
                $dropped[] = $code !== '' ? $code : '(no code given)';
                continue;
            }

            $quantity = max(1, (int) ($suggestion['quantity'] ?? 1));

            $suggestions[] = [
                'service_id'  => (int) $service['id'],
                'code'        => $service['code'],
                'name'        => $service['name'],
                'quantity'    => $quantity,
                'unit_price'  => $service['price'] !== null ? Money::round($service['price']) : null,
                'line_total'  => $service['price'] !== null
                    ? Money::round(Money::multiply($service['price'], (string) $quantity))
                    : null,
                'confidence'  => in_array($suggestion['confidence'] ?? '', ['high', 'medium', 'low'], true)
                    ? $suggestion['confidence'] : 'low',
                'evidence'    => (string) ($suggestion['evidence'] ?? ''),
                'has_price'   => $service['price'] !== null,
            ];
        }

        $notes = array_values(array_filter(array_map('strval', $result['notes'] ?? [])));

        if ($dropped !== []) {
            // Reported, not hidden: a biller should know the model reached for
            // something the clinic does not sell.
            $notes[] = 'Ignored codes that are not in the catalogue: ' . implode(', ', $dropped) . '.';
        }

        return [
            'encounter_id'     => $encounterId,
            'already_invoiced' => $existing !== null ? $existing['invoice_no'] : null,
            'suggestions'      => $suggestions,
            'notes'            => $notes,
            'estimated_total'  => Money::round(Money::sum(
                array_map(static fn(array $s): string => $s['line_total'] ?? '0', $suggestions),
            )),
            'requires_approval' => true,
        ];
    }

    // ===============================================================
    // 3. AI Claim Assistant (§9)
    // ===============================================================

    /**
     * Check a claim before it is submitted: what is missing, what is likely to
     * be rejected, and how risky it looks.
     *
     * The score and missing-items list are stored on the claim (the schema has
     * `ai_risk_score` and `ai_missing_items` for exactly this), but nothing is
     * submitted or blocked. A biller may send a high-risk claim anyway — and
     * often should, because only they know the context.
     *
     * @return array<string,mixed>
     */
    public function reviewClaim(int $claimId): array
    {
        $this->plan()->assertWithin('ai_calls');               // §22

        $claim = $this->claims()->findDetailed($claimId);
        if ($claim === null) {
            throw new NotFoundException('Claim not found');
        }

        // Past rejections from this insurer are the most useful signal
        // available, so give them to the model.
        $history = Database::select(
            'SELECT c.rejection_code, c.rejection_reason, COUNT(*) AS times
               FROM claims c
               JOIN insurance_policies ip ON ip.id = c.insurance_policy_id
              WHERE c.organization_id = :org
                AND ip.insurance_provider_id = (
                    SELECT insurance_provider_id FROM insurance_policies WHERE id = :pid
                )
                AND c.status IN (\'rejected\', \'partially_approved\')
                AND c.rejection_reason IS NOT NULL
              GROUP BY c.rejection_code, c.rejection_reason
              ORDER BY times DESC
              LIMIT 10',
            ['org' => $this->requireOrganization(), 'pid' => (int) $claim['insurance_policy_id']],
        );

        $system = <<<'TXT'
You are an insurance claims assistant. Review a claim before it is sent and
flag what would get it rejected.

Rules you must not break:
- You are advising, not deciding. The biller sends the claim, not you.
- Base every point on the claim as given. Do not assume documents exist or do
  not exist beyond what you are told.
- A line with no diagnosis code attached is the single most common rejection
  cause - check for it.
- Be specific. "Missing documentation" is useless; name the document.

Reply with a single JSON object and nothing else:
{
  "risk_score": 0-100,
  "risk_level": "low" | "medium" | "high",
  "missing": ["specific things to attach or fix before sending"],
  "likely_rejections": [
    {"reason": "what the insurer would say", "line": "which line, or 'whole claim'"}
  ],
  "ready_to_submit": true | false,
  "summary": "one sentence for the biller"
}
TXT;

        $prompt = $this->claimContext($claim, $history);

        $result = AiProviders::resolve()->completeJson(
            $system, $prompt, ['max_tokens' => 2000, 'task' => 'claim'],
        );

        // Counted only once the provider actually answered — a failed call
        // must not spend the clinic's monthly allowance.
        $this->plan()->recordUsage('ai_calls');

        $score = max(0, min(100, (int) ($result['risk_score'] ?? 50)));
        $missing = array_values(array_filter(array_map('strval', $result['missing'] ?? [])));

        // Persist the advisory result; the claim's status is untouched.
        Database::statement(
            'UPDATE claims
                SET ai_risk_score = :score, ai_missing_items = :missing, updated_at = :now
              WHERE organization_id = :org AND id = :id',
            [
                'score'   => $score,
                'missing' => json_encode($missing, JSON_UNESCAPED_UNICODE),
                'now'     => now(),
                'org'     => $this->requireOrganization(),
                'id'      => $claimId,
            ],
        );

        return [
            'claim_id'          => $claimId,
            'claim_no'          => $claim['claim_no'],
            'risk_score'        => $score,
            'risk_level'        => in_array($result['risk_level'] ?? '', ['low', 'medium', 'high'], true)
                ? $result['risk_level'] : ($score >= 60 ? 'high' : ($score >= 30 ? 'medium' : 'low')),
            'missing'           => $missing,
            'likely_rejections' => $result['likely_rejections'] ?? [],
            'ready_to_submit'   => (bool) ($result['ready_to_submit'] ?? false),
            'summary'           => (string) ($result['summary'] ?? ''),
            // Restated on every response so no client treats this as a gate.
            'advisory_only'     => true,
        ];
    }

    // ===============================================================
    // Context builders
    // ===============================================================

    /** @param array<string,mixed> $record */
    private function encounterContext(array $record): string
    {
        $parts = [
            'Patient: ' . $record['patient_name']
                . ($record['patient_age'] !== null ? ', ' . $record['patient_age'] . ' years' : '')
                . ', ' . $record['gender'],
            'Seen by: ' . $record['doctor_name'] . ' (' . $record['specialty'] . ')',
            'Complaint: ' . ($record['chief_complaint'] ?: 'not recorded'),
        ];

        foreach (['symptoms' => 'Symptoms', 'examination' => 'Examination'] as $key => $label) {
            if (!empty($record[$key])) {
                $parts[] = "$label: {$record[$key]}";
            }
        }

        $vitals = array_filter([
            $record['bp_systolic'] && $record['bp_diastolic']
                ? "BP {$record['bp_systolic']}/{$record['bp_diastolic']}" : null,
            $record['pulse'] ? "pulse {$record['pulse']}" : null,
            $record['temperature_c'] ? "temp {$record['temperature_c']}C" : null,
            $record['weight_kg'] ? "weight {$record['weight_kg']}kg" : null,
        ]);
        if ($vitals !== []) {
            $parts[] = 'Vitals: ' . implode(', ', $vitals);
        }

        foreach ($record['diagnoses'] as $diagnosis) {
            $parts[] = "Diagnosis ({$diagnosis['type']}): {$diagnosis['description']}"
                     . ($diagnosis['icd10_code'] ? " [{$diagnosis['icd10_code']}]" : '');
        }
        foreach ($record['procedures'] as $procedure) {
            $parts[] = "Procedure performed: {$procedure['name']}"
                     . ($procedure['site'] ? " at {$procedure['site']}" : '');
        }
        foreach ($record['lab_orders'] as $order) {
            $parts[] = "Lab ordered: {$order['order_no']} ({$order['priority']})";
        }
        foreach ($record['prescriptions'] as $prescription) {
            foreach ($prescription['items'] as $item) {
                $parts[] = "Prescribed: {$item['medication_name']} "
                         . trim("{$item['dosage']} {$item['frequency']} {$item['duration']}");
            }
        }

        return implode("\n", $parts);
    }

    /**
     * @param array<string,mixed> $claim
     * @param list<array<string,mixed>> $history
     */
    private function claimContext(array $claim, array $history): string
    {
        $parts = [
            "Claim {$claim['claim_no']} to {$claim['provider_name']}",
            "Policy {$claim['policy_number']}, member {$claim['member_id']}",
            "Claiming {$claim['currency_code']} {$claim['claimed_amount']} "
                . "of an invoice totalling {$claim['invoice_total']}",
            'Lines:',
        ];

        foreach ($claim['items'] as $item) {
            $parts[] = sprintf(
                '  - %s | code %s | diagnosis %s | %s',
                $item['description'],
                $item['billing_code'] ?: 'NONE',
                $item['diagnosis_code'] ?: 'NONE',
                $item['claimed_amount'],
            );
        }

        if ($claim['encounter_no']) {
            $parts[] = "Linked visit: {$claim['encounter_no']}";
        } else {
            $parts[] = 'No clinical visit is linked to this claim.';
        }

        if ($history !== []) {
            $parts[] = "\nThis insurer has previously rejected claims for:";
            foreach ($history as $row) {
                $parts[] = sprintf(
                    '  - [%s] %s (%d times)',
                    $row['rejection_code'] ?: 'no code',
                    $row['rejection_reason'],
                    (int) $row['times'],
                );
            }
        }

        return implode("\n", $parts);
    }


    // ===============================================================
    // 4. Patient summary (§25 Phase 6)
    // ===============================================================

    /**
     * A clinician opening an unfamiliar chart: what matters, in a paragraph.
     *
     * The record is already on the screen — the point here is precedence. Which
     * of nine visits and four conditions should be read first is a judgement,
     * and it is exactly the judgement a busy clinician is short of time to make.
     *
     * Advisory, like everything else in this file: it never becomes a note, and
     * the chart it summarises stays untouched.
     *
     * @return array<string,mixed>
     */
    public function summarisePatient(int $patientId): array
    {
        $this->plan()->assertWithin('ai_calls');               // §22

        $patient = Database::selectOne(
            'SELECT p.*, TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) AS age
               FROM patients p
              WHERE p.organization_id = :org AND p.id = :id',
            ['org' => $this->requireOrganization(), 'id' => $patientId],
        );

        if ($patient === null) {
            throw new NotFoundException('Patient not found');
        }

        $context = $this->patientContext($patientId, $patient);

        $system = <<<'TXT'
You are summarising a patient record for a clinician who is about to see them.

Rules you must not break:
- Use ONLY what is in the record below. Never add a condition, medicine,
  allergy or finding that is not there.
- You are not diagnosing and not recommending treatment. You are ordering what
  is already recorded by how much it matters to the consultation about to
  happen.
- Allergies and active conditions come first. Say plainly when there are none.
- If the record is thin, say so rather than padding it.

Reply with a single JSON object and nothing else:
{
  "summary": "two or three sentences a clinician can read in ten seconds",
  "key_points": ["the handful of things that would change how you treat them"],
  "watch_for": ["allergies, interactions or gaps worth checking before prescribing"]
}
TXT;

        $result = AiProviders::resolve()->completeJson(
            $system, $context, ['max_tokens' => 1500, 'task' => 'summary'],
        );

        $this->plan()->recordUsage('ai_calls');

        return [
            'patient_id' => $patientId,
            'summary'    => (string) ($result['summary'] ?? ''),
            'key_points' => array_values(array_filter(array_map('strval', $result['key_points'] ?? []))),
            'watch_for'  => array_values(array_filter(array_map('strval', $result['watch_for'] ?? []))),
            // Restated on every response so no client treats this as the record.
            'advisory_only' => true,
        ];
    }

    /**
     * Everything the summary is allowed to draw on, as plain text.
     *
     * Assembled here rather than passed in, so the model can never be handed a
     * record the caller was not entitled to read: it is built from this
     * organization and this patient, both already checked.
     */
    private function patientContext(int $patientId, array $patient): string
    {
        $args = ['org' => $this->requireOrganization(), 'pid' => $patientId];

        $parts = [
            'Patient: ' . $patient['first_name'] . ' ' . $patient['last_name']
                . (isset($patient['age']) ? ', ' . $patient['age'] . ' years' : '')
                . ', ' . $patient['gender']
                . ($patient['blood_group'] ? ', blood group ' . $patient['blood_group'] : ''),
        ];

        $allergies = Database::select(
            'SELECT substance, severity, reaction FROM allergies
              WHERE organization_id = :org AND patient_id = :pid AND is_active = 1',
            $args,
        );
        $parts[] = 'Allergies: ' . ($allergies === [] ? 'none recorded' : implode('; ', array_map(
            static fn(array $a): string => $a['substance'] . ' (' . $a['severity'] . ')'
                . ($a['reaction'] ? ' - ' . $a['reaction'] : ''),
            $allergies,
        )));

        $conditions = Database::select(
            'SELECT name, status FROM medical_conditions
              WHERE organization_id = :org AND patient_id = :pid',
            $args,
        );
        $parts[] = 'Conditions: ' . ($conditions === [] ? 'none recorded' : implode('; ', array_map(
            static fn(array $c): string => $c['name'] . ' (' . $c['status'] . ')',
            $conditions,
        )));

        $visits = Database::select(
            "SELECT e.encounter_no, e.chief_complaint, e.completed_at,
                    GROUP_CONCAT(d.description SEPARATOR ', ') AS diagnoses
               FROM encounters e
               LEFT JOIN diagnoses d ON d.encounter_id = e.id
              WHERE e.organization_id = :org AND e.patient_id = :pid
                AND e.status = 'completed'
              GROUP BY e.id
              ORDER BY e.completed_at DESC
              LIMIT 5",
            $args,
        );
        foreach ($visits as $visit) {
            $parts[] = 'Visit ' . substr((string) $visit['completed_at'], 0, 10) . ': '
                . ($visit['chief_complaint'] ?: 'consultation')
                . ($visit['diagnoses'] ? ' -> ' . $visit['diagnoses'] : '');
        }

        $medicines = Database::select(
            "SELECT pi.medication_name, pi.dosage, pi.frequency, pi.duration
               FROM prescription_items pi
               JOIN prescriptions p ON p.id = pi.prescription_id
              WHERE p.organization_id = :org AND p.patient_id = :pid
                AND p.status = 'issued'
              ORDER BY p.issued_at DESC
              LIMIT 12",
            $args,
        );
        foreach ($medicines as $m) {
            $parts[] = 'Prescribed: ' . $m['medication_name'] . ' '
                . trim(($m['dosage'] ?? '') . ' ' . ($m['frequency'] ?? '') . ' ' . ($m['duration'] ?? ''));
        }

        return implode("\n", $parts);
    }


    // ===============================================================
    // 5. Search across the record (§25 Phase 6)
    // ===============================================================

    /**
     * One box that finds a patient however you remember them.
     *
     * Deliberately NOT an AI feature, though §25 lists it under the AI phase.
     * A receptionist typing a phone number needs an answer in 40ms and needs it
     * to be the same answer every time; sending that to a language model would
     * make it slower, less predictable and metered. So this is SQL — the
     * "intelligence" is in searching the record's several surfaces at once and
     * saying which one matched.
     *
     * Searched: patient name, MRN, phone, email; invoice and prescription
     * numbers; diagnoses; and the medicines a patient has been given.
     *
     * @return array<string,mixed>
     */
    public function search(string $term, int $limit = 20): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            throw new ValidationException(['q' => ['Type at least two characters.']]);
        }

        $org  = $this->requireOrganization();
        $like = '%' . $term . '%';
        $take = max(1, min(50, $limit));

        // Every branch is tenant-scoped and every value is bound. The term
        // reaches SQL only as a parameter, never as text.
        $patients = Database::select(
            "SELECT id, mrn, first_name, last_name, phone, email, status
               FROM patients
              WHERE organization_id = :org
                AND (CONCAT(first_name, ' ', last_name) LIKE :q
                     OR mrn LIKE :q2 OR phone LIKE :q3 OR email LIKE :q4)
              ORDER BY first_name
              LIMIT $take",
            ['org' => $org, 'q' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like],
        );

        $invoices = Database::select(
            "SELECT i.id, i.invoice_no, i.status, i.grand_total, i.balance_due,
                    i.currency_code, i.patient_id,
                    CONCAT(p.first_name, ' ', p.last_name) AS patient_name
               FROM invoices i
               JOIN patients p ON p.id = i.patient_id
              WHERE i.organization_id = :org AND i.invoice_no LIKE :q
              ORDER BY i.id DESC
              LIMIT $take",
            ['org' => $org, 'q' => $like],
        );

        $prescriptions = Database::select(
            "SELECT rx.id, rx.prescription_no, rx.status, rx.patient_id,
                    CONCAT(p.first_name, ' ', p.last_name) AS patient_name
               FROM prescriptions rx
               JOIN patients p ON p.id = rx.patient_id
              WHERE rx.organization_id = :org AND rx.prescription_no LIKE :q
              ORDER BY rx.id DESC
              LIMIT $take",
            ['org' => $org, 'q' => $like],
        );

        // "Who have I diagnosed with this?" — a question the patient list
        // cannot answer, and the reason this is worth having at all.
        $diagnoses = Database::select(
            "SELECT d.description, d.icd10_code, d.encounter_id, e.patient_id,
                    e.encounter_no, e.completed_at,
                    CONCAT(p.first_name, ' ', p.last_name) AS patient_name
               FROM diagnoses d
               JOIN encounters e ON e.id = d.encounter_id
               JOIN patients p   ON p.id = e.patient_id
              WHERE d.organization_id = :org
                AND (d.description LIKE :q OR d.icd10_code LIKE :q2)
              ORDER BY e.completed_at DESC
              LIMIT $take",
            ['org' => $org, 'q' => $like, 'q2' => $like],
        );

        $medicines = Database::select(
            "SELECT pi.medication_name, pi.dosage, rx.prescription_no, rx.id AS prescription_id,
                    rx.patient_id, CONCAT(p.first_name, ' ', p.last_name) AS patient_name
               FROM prescription_items pi
               JOIN prescriptions rx ON rx.id = pi.prescription_id
               JOIN patients p       ON p.id = rx.patient_id
              WHERE rx.organization_id = :org AND pi.medication_name LIKE :q
              ORDER BY rx.id DESC
              LIMIT $take",
            ['org' => $org, 'q' => $like],
        );

        return [
            'query'   => $term,
            'results' => [
                'patients'      => $patients,
                'invoices'      => $invoices,
                'prescriptions' => $prescriptions,
                'diagnoses'     => $diagnoses,
                'medicines'     => $medicines,
            ],
            'total' => count($patients) + count($invoices) + count($prescriptions)
                     + count($diagnoses) + count($medicines),
        ];
    }

    /** @param array<string,mixed> $draft */
    private function renderSoap(array $draft): string
    {
        $sections = [
            'SUBJECTIVE' => $draft['subjective'] ?? '',
            'OBJECTIVE'  => $draft['objective'] ?? '',
            'ASSESSMENT' => $draft['assessment'] ?? '',
            'PLAN'       => $draft['plan'] ?? '',
        ];

        $out = [];
        foreach ($sections as $heading => $text) {
            if (trim((string) $text) !== '') {
                $out[] = "$heading\n" . trim((string) $text);
            }
        }

        $uncertainties = array_filter(array_map('strval', $draft['uncertainties'] ?? []));
        if ($uncertainties !== []) {
            $out[] = "TO CONFIRM\n- " . implode("\n- ", $uncertainties);
        }

        return implode("\n\n", $out);
    }
}
