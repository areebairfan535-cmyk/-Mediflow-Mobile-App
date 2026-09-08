<?php
declare(strict_types=1);

namespace App\Services\Payments;

/**
 * A gateway that approves everything, for development and the test suite.
 *
 * The same idea as StubProvider on the AI side, and refused in production for
 * the same reason: a payment that was never taken must never be able to
 * settle a real invoice.
 *
 * It exists because the interesting half of online payment is not PayPal — it
 * is what this codebase does around it. That the amount comes off the invoice
 * and not the request, that a reference is captured once and not twice, that
 * the ledger matches what was actually taken. All of that is testable without
 * anyone's credentials, and worth testing on every run rather than only when
 * somebody remembers to try a sandbox account.
 *
 * There is no store behind it, so the reference carries what capture() needs
 * to answer. That is a stub's trick and nothing to copy: a real provider is
 * asked what it took, and is the only thing worth believing.
 */
final class StubGateway implements PaymentGateway
{
    private const PREFIX = 'STUB';

    public function isConfigured(): bool
    {
        return true;
    }

    /** Everything, so a currency is never the reason a test fails. */
    public function supportsCurrency(string $currency): bool
    {
        return trim($currency) !== '';
    }

    public function name(): string
    {
        return 'stub';
    }

    public function unavailableReason(): ?string
    {
        return null;
    }

    public function createPayment(string $amount, string $currency, array $context = []): array
    {
        $reference = self::PREFIX . '-' . rtrim(strtr(base64_encode(json_encode([
            'i' => (string) ($context['invoice_id'] ?? ''),
            'a' => $amount,
            'c' => strtoupper($currency),
            'n' => bin2hex(random_bytes(6)),
        ])), '+/', '-_'), '=');

        $base = rtrim((string) env('APP_URL', 'http://localhost:8000'), '/');

        return [
            'reference'    => $reference,
            'approval_url' => $base . '/payment/stub?reference=' . urlencode($reference),
        ];
    }

    public function capture(string $reference): array
    {
        $payload = $this->decode($reference);

        return [
            'reference'  => $reference,
            'amount'     => (string) $payload['a'],
            'currency'   => (string) $payload['c'],
            'paid_at'    => gmdate('Y-m-d H:i:s'),
            'invoice_id' => (string) $payload['i'],
            'raw'        => ['stub' => true, 'reference' => $reference],
        ];
    }

    /** @return array{i: string, a: string, c: string, n: string} */
    private function decode(string $reference): array
    {
        if (!str_starts_with($reference, self::PREFIX . '-')) {
            throw new GatewayUnavailable('That is not a payment this gateway opened.');
        }

        $encoded = substr($reference, strlen(self::PREFIX) + 1);
        $json    = base64_decode(strtr($encoded, '-_', '+/'), true);
        $decoded = $json === false ? null : json_decode($json, true);

        if (!is_array($decoded) || !isset($decoded['i'], $decoded['a'], $decoded['c'])) {
            throw new GatewayUnavailable('That payment reference is not readable.');
        }

        return $decoded;
    }
}
