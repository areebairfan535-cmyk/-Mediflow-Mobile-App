<?php
declare(strict_types=1);

namespace App\Services\Notifications;

/**
 * WhatsApp through the Cloud API (§20).
 *
 * §20 lists WhatsApp as the future channel, and the notifications table has
 * carried 'whatsapp' in its channel enum since the first migration. What was
 * missing was the class: a row queued on that channel found no handler and was
 * marked skipped with "No handler for this channel" — which reads like a bug
 * rather than a decision.
 *
 * It is here now, unconfigured. With no WHATSAPP_ENDPOINT set it reports
 * SKIPPED, which is the honest answer and costs nothing; the day a clinic has
 * a business account, two .env lines turn it on.
 *
 * ---------------------------------------------------------------------------
 * Worth knowing before switching it on
 * ---------------------------------------------------------------------------
 * WhatsApp does not let a business send arbitrary text to somebody who has not
 * messaged first. Outside a 24-hour window opened by the patient, only a
 * pre-approved TEMPLATE may be sent, and templates are approved by Meta, not
 * by us. So WHATSAPP_TEMPLATE names the template and the body is passed as its
 * parameter — sending raw prose would be rejected by the platform for every
 * appointment reminder, which is exactly the case this channel exists for.
 *
 * A clinic also needs the patient's consent on record. That is a policy the
 * clinic owns, not something this class can assert.
 */
final class WhatsAppChannel implements Channel
{
    private ?string $endpoint;
    private ?string $token;
    private ?string $template;
    private string $language;

    public function __construct()
    {
        // e.g. https://graph.facebook.com/v21.0/<phone-number-id>/messages
        $this->endpoint = env('WHATSAPP_ENDPOINT');
        $this->token    = env('WHATSAPP_TOKEN');
        $this->template = env('WHATSAPP_TEMPLATE');
        $this->language = (string) env('WHATSAPP_LANGUAGE', 'en');
    }

    public function isConfigured(): bool
    {
        return $this->endpoint !== null && $this->endpoint !== ''
            && $this->token !== null && $this->token !== '';
    }

    public function name(): string
    {
        return 'whatsapp';
    }

    public function send(array $notification): string
    {
        if (!$this->isConfigured()) {
            return self::SKIPPED;
        }

        $to = $this->msisdn((string) ($notification['to_address'] ?? ''));
        if ($to === '') {
            return self::SKIPPED;
        }

        $text = trim(
            (string) ($notification['title'] ?? '') . ': ' . (string) ($notification['body'] ?? '')
        );

        $payload = $this->template !== null && $this->template !== ''
            ? [
                'messaging_product' => 'whatsapp',
                'to'                => $to,
                'type'              => 'template',
                'template'          => [
                    'name'       => $this->template,
                    'language'   => ['code' => $this->language],
                    'components' => [[
                        'type'       => 'body',
                        'parameters' => [['type' => 'text', 'text' => $text]],
                    ]],
                ],
            ]
            // Only valid inside a session the patient opened. Left available
            // because a clinic replying to an enquiry is a real case.
            : [
                'messaging_product' => 'whatsapp',
                'to'                => $to,
                'type'              => 'text',
                'text'              => ['body' => $text],
            ];

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'timeout'       => 15,
                'ignore_errors' => true,
                'header'        => "Content-Type: application/json\r\n"
                                 . "Authorization: Bearer {$this->token}\r\n",
                'content'       => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ],
        ]);

        $response = @file_get_contents((string) $this->endpoint, false, $context);
        $status   = $this->statusFrom($http_response_header ?? []);

        if ($response === false || $status >= 400) {
            throw new \RuntimeException("WhatsApp API returned $status");
        }

        return self::SENT;
    }

    /**
     * Digits only, no leading +.
     *
     * The Cloud API wants a bare international number. A stored "+92 300
     * 1234567" is the same number a human would write down, and rejecting it
     * for its punctuation would be the wrong place to be strict.
     */
    private function msisdn(string $raw): string
    {
        return ltrim(preg_replace('/\D+/', '', $raw) ?? '', '0');
    }

    /** @param list<string> $headers */
    private function statusFrom(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                return (int) $m[1];
            }
        }
        return 0;
    }
}
