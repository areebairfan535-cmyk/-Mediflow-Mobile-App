<?php
declare(strict_types=1);

namespace App\Services\Notifications;

/**
 * SMS through an HTTP gateway.
 *
 * Gateways differ in every detail, so the request shape is configuration
 * rather than code: SMS_ENDPOINT with {to} and {message} placeholders, an
 * optional SMS_API_KEY, and that is the whole contract. Adding JazzCash or
 * Twilio is two .env lines, not a class.
 */
final class SmsChannel implements Channel
{
    private ?string $endpoint;
    private ?string $apiKey;
    private string $method;

    public function __construct()
    {
        $this->endpoint = env('SMS_ENDPOINT');
        $this->apiKey   = env('SMS_API_KEY');
        $this->method   = strtoupper((string) env('SMS_METHOD', 'POST'));
    }

    public function isConfigured(): bool
    {
        return $this->endpoint !== null && $this->endpoint !== '';
    }

    public function name(): string
    {
        return 'sms';
    }

    public function send(array $notification): string
    {
        if (!$this->isConfigured()) {
            return self::SKIPPED;
        }

        $to = trim((string) ($notification['to_address'] ?? ''));
        if ($to === '') {
            return self::SKIPPED;
        }

        $text = trim(
            (string) ($notification['title'] ?? '') . ': ' . (string) ($notification['body'] ?? '')
        );

        $url  = str_replace(
            ['{to}', '{message}'],
            [rawurlencode($to), rawurlencode($text)],
            (string) $this->endpoint,
        );

        $context = [
            'http' => [
                'method'        => $this->method,
                'timeout'       => 15,
                'ignore_errors' => true,
                'header'        => "Content-Type: application/json\r\n"
                                 . ($this->apiKey ? "Authorization: Bearer {$this->apiKey}\r\n" : ''),
            ],
        ];

        if ($this->method === 'POST') {
            $context['http']['content'] = json_encode(['to' => $to, 'message' => $text]);
        }

        $response = @file_get_contents($url, false, stream_context_create($context));
        $status   = $this->statusFrom($http_response_header ?? []);

        if ($response === false || $status >= 400) {
            throw new \RuntimeException("SMS gateway returned $status");
        }

        return self::SENT;
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
