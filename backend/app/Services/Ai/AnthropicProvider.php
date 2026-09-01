<?php
declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Claude, over the Messages API.
 *
 * Raw HTTP rather than the official PHP SDK, deliberately: §24 specifies
 * "Core PHP, OOP, MVC" and this project has no Composer at all — every other
 * external call (none, so far) would face the same constraint. Pulling in a
 * dependency manager for one optional feature would change the project's
 * stated architecture. The request shape below is the documented one.
 *
 * The key is read from the environment and never logged, never returned, and
 * never written to an audit row.
 */
final class AnthropicProvider implements AiProvider
{
    private const ENDPOINT   = 'https://api.anthropic.com/v1/messages';
    private const APIVERSION = '2023-06-01';

    private string $apiKey;
    private string $model;
    private int $timeout;

    public function __construct(?string $apiKey = null, ?string $model = null, ?int $timeout = null)
    {
        $this->apiKey  = trim((string) ($apiKey ?? env('ANTHROPIC_API_KEY', '')));
        $this->model   = (string) ($model ?? env('AI_MODEL', 'claude-opus-5'));
        $this->timeout = $timeout ?? (int) env('AI_TIMEOUT_SECONDS', '60');
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function name(): string
    {
        return 'anthropic';
    }

    public function model(): ?string
    {
        return $this->isConfigured() ? $this->model : null;
    }

    /** @return array<string,mixed> */
    public function completeJson(string $system, string $prompt, array $options = []): array
    {
        if (!$this->isConfigured()) {
            throw new AiUnavailable('ANTHROPIC_API_KEY is not set in backend/.env.');
        }

        $payload = [
            'model'      => $this->model,
            'max_tokens' => (int) ($options['max_tokens'] ?? 2000),
            // The rules live in the system prompt so they cannot be displaced
            // by whatever clinical text arrives in the user turn.
            'system'     => $system,
            'messages'   => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        $raw = $this->post($payload);

        return $this->decodeJsonObject($this->extractText($raw));
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function post(array $payload): array
    {
        $ch = curl_init(self::ENDPOINT);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => [
                'content-type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: ' . self::APIVERSION,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new AiUnavailable('Could not reach the AI provider: ' . $error);
        }

        $decoded = json_decode((string) $body, true);

        if ($status !== 200 || !is_array($decoded)) {
            // The provider's message can quote the request; never echo it
            // verbatim to a client in case it contains anything sensitive.
            $reason = is_array($decoded) ? ($decoded['error']['message'] ?? 'unknown error') : 'bad response';
            error_log("[ai] anthropic HTTP $status: " . substr((string) $body, 0, 400));

            throw new AiUnavailable(match (true) {
                $status === 401 => 'The AI API key was rejected. Check ANTHROPIC_API_KEY.',
                $status === 429 => 'The AI provider is rate limiting. Try again shortly.',
                $status >= 500  => 'The AI provider is having problems. Try again shortly.',
                default         => 'The AI request failed: ' . $reason,
            });
        }

        return $decoded;
    }

    /** @param array<string,mixed> $response */
    private function extractText(array $response): string
    {
        // A safety decline arrives as HTTP 200 — check before reading content.
        if (($response['stop_reason'] ?? null) === 'refusal') {
            throw new AiUnavailable(
                'The AI declined to answer this request. Write it by hand.'
            );
        }

        $text = '';
        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'] ?? '';
            }
        }

        if (trim($text) === '') {
            throw new AiUnavailable('The AI returned an empty response.');
        }

        return $text;
    }

    /**
     * Pull a JSON object out of the reply.
     *
     * Models sometimes wrap JSON in prose or code fences even when told not to.
     * Rather than fail the whole request on formatting, strip the usual
     * wrappers and parse what is inside — the same defensive approach the
     * assistants' prompts already ask for.
     *
     * @return array<string,mixed>
     */
    private function decodeJsonObject(string $text): array
    {
        $trimmed = trim($text);

        // ```json ... ``` fences
        if (str_starts_with($trimmed, '```')) {
            $newline = strpos($trimmed, "\n");
            if ($newline !== false) {
                $trimmed = substr($trimmed, $newline + 1);
            }
            if (str_ends_with($trimmed, '```')) {
                $trimmed = substr($trimmed, 0, -3);
            }
            $trimmed = trim($trimmed);
        }

        // Prose either side of the object.
        $start = strpos($trimmed, '{');
        $end   = strrpos($trimmed, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $trimmed = substr($trimmed, $start, $end - $start + 1);
        }

        $decoded = json_decode($trimmed, true);

        if (!is_array($decoded)) {
            error_log('[ai] non-JSON reply: ' . substr($text, 0, 300));
            throw new AiUnavailable('The AI returned something this app could not read.');
        }

        return $decoded;
    }
}
