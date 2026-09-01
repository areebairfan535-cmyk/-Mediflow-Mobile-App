<?php
declare(strict_types=1);

namespace App\Services\Ai;

/**
 * An offline provider that answers from rules, not from a model.
 *
 * Why it exists: §9's assistants need a real API key, and until a clinic has
 * one there is no way to see, demo or test the approval gates that make the AI
 * module safe — the drafted note nobody has approved, the suggestion nobody
 * has ticked. Without this, the most safety-critical part of Phase 6 would
 * ship untested.
 *
 * What it is NOT: intelligence. It echoes the clinician's own words back in
 * SOAP headings, matches catalogue names against the visit text, and counts
 * missing fields on a claim. Every answer it gives says so in the text a user
 * reads, so nobody can mistake it for analysis.
 *
 * It refuses to run when APP_ENV=production (see AiProviders), because
 * placeholder text in a real chart is exactly the failure §9 is written to
 * prevent.
 */
final class StubProvider implements AiProvider
{
    private const MARK = 'Generated offline by MediFlow\'s stub assistant — no language '
                       . 'model was called. Set AI_PROVIDER=anthropic with a key for real output.';

    public function completeJson(string $system, string $prompt, array $options = []): array
    {
        return match ($options['task'] ?? '') {
            'note'    => $this->note($prompt),
            'billing' => $this->billing($prompt),
            'claim'   => $this->claim($prompt),
            'summary' => $this->summary($prompt),
            // An unknown task means a new assistant was added without teaching
            // the stub about it. Say so rather than returning a plausible shape.
            default   => throw new AiUnavailable(
                'The stub AI provider has no canned answer for this assistant.'
            ),
        };
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'stub (offline)';
    }

    public function model(): ?string
    {
        return 'rules-only, no model';
    }

    // ---------------------------------------------------------------

    /**
     * Shorthand in, the same words under SOAP headings out.
     *
     * Deliberately no expansion of abbreviations: guessing what "sob" means is
     * the part that needs a model, and a wrong guess in a chart is dangerous.
     *
     * @return array<string,mixed>
     */
    private function note(string $prompt): array
    {
        $shorthand = trim($this->after($prompt, "Clinician's shorthand:"));
        $context   = trim($this->between($prompt, 'Visit context:', "Clinician's shorthand:"));

        return [
            'subjective' => $shorthand !== '' ? $shorthand : 'Not recorded.',
            'objective'  => $this->contextLine($context, 'Vitals')
                         ?: $this->contextLine($context, 'Examination')
                         ?: 'See recorded findings.',
            'assessment' => $this->contextLine($context, 'Diagnoses')
                         ?: 'No diagnosis recorded at the time of writing.',
            'plan'       => $this->contextLine($context, 'Prescription')
                         ?: 'As discussed with the patient.',
            'uncertainties' => [
                self::MARK,
                'Abbreviations were left exactly as written — expand them yourself.',
            ],
        ];
    }

    /**
     * Suggest a catalogue service wherever its name appears in the visit text.
     *
     * A plain substring match, and it says as much: it will miss a procedure
     * described in other words, which is precisely the gap a real model fills.
     *
     * @return array<string,mixed>
     */
    private function billing(string $prompt): array
    {
        $catalogue = $this->between($prompt, 'Clinic catalogue (code | name | price):', 'Visit record:');
        $record    = strtolower($this->after($prompt, 'Visit record:'));

        $suggestions = [];

        foreach (preg_split('/\r?\n/', trim($catalogue)) ?: [] as $line) {
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) < 2 || $parts[0] === '') {
                continue;
            }

            [$code, $name] = $parts;
            $needle = strtolower($name);

            if ($needle === '' || !str_contains($record, $needle)) {
                continue;
            }

            $suggestions[] = [
                'code'       => $code,
                'quantity'   => 1,
                'confidence' => 'low',
                'evidence'   => sprintf('"%s" appears in the visit record.', $name),
            ];
        }

        return [
            'suggestions' => array_slice($suggestions, 0, 8),
            'notes'       => [
                self::MARK,
                'Matching is literal: anything the clinician described in different '
                . 'words was missed. Check the visit yourself before invoicing.',
            ],
        ];
    }

    /**
     * Count what a claim is missing. No judgement, just the checks that can be
     * made from the text — which is why every score comes back "medium" at best.
     *
     * @return array<string,mixed>
     */
    private function claim(string $prompt): array
    {
        $lower   = strtolower($prompt);
        $missing = [];

        if (!str_contains($lower, 'diagnosis') || str_contains($lower, 'diagnosis: —')) {
            $missing[] = 'No diagnosis code is attached — the most common rejection cause.';
        }
        if (!str_contains($lower, 'clinical note') && !str_contains($lower, 'note:')) {
            $missing[] = 'No clinical note appears on the encounter behind this claim.';
        }
        if (str_contains($lower, 'previous rejection')) {
            $missing[] = 'This insurer has rejected claims before — read the reasons above.';
        }

        $score = min(60, count($missing) * 20);

        return [
            'risk_score'        => $score,
            'risk_level'        => $score >= 40 ? 'medium' : 'low',
            'missing'           => $missing,
            'likely_rejections' => [],
            'ready_to_submit'   => $missing === [],
            'summary'           => self::MARK,
        ];
    }


    /**
     * Reads the record back in the order it was given — allergies first,
     * because that is the order that matters. No judgement is applied, which
     * is precisely what a real model is for.
     *
     * @return array<string,mixed>
     */
    private function summary(string $prompt): array
    {
        $lines = array_values(array_filter(array_map(
            'trim',
            preg_split('/\r?\n/', $prompt) ?: [],
        )));
        $allergies = '';
        $points    = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, 'Allergies:')) { $allergies = $line; }
            if (str_starts_with($line, 'Conditions:') || str_starts_with($line, 'Visit ')) {
                $points[] = $line;
            }
        }

        return [
            'summary'    => ($lines[0] ?? '') . ' ' . $allergies . ' ' . self::MARK,
            'key_points' => array_slice($points, 0, 6),
            'watch_for'  => $allergies !== '' && !str_contains($allergies, 'none recorded')
                ? [$allergies] : ['No allergies are recorded.'],
        ];
    }

    // ---------------------------------------------------------------

    private function after(string $haystack, string $marker): string
    {
        $at = strpos($haystack, $marker);
        return $at === false ? '' : substr($haystack, $at + strlen($marker));
    }

    private function between(string $haystack, string $from, string $to): string
    {
        $tail = $this->after($haystack, $from);
        $at   = strpos($tail, $to);
        return $at === false ? $tail : substr($tail, 0, $at);
    }

    /** The first line of the visit context starting with a given label. */
    private function contextLine(string $context, string $label): string
    {
        foreach (preg_split('/\r?\n/', $context) ?: [] as $line) {
            if (stripos(trim($line), $label) === 0) {
                return trim($line);
            }
        }
        return '';
    }
}
