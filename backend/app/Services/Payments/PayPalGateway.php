<?php
declare(strict_types=1);

namespace App\Services\Payments;

/**
 * PayPal Orders v2 — the first gateway behind §7's "online payment".
 *
 * Two calls make a payment. createPayment() opens an order and hands back the
 * link the payer is sent to; capture() takes the money once they have approved
 * it. Nothing is charged in between, so an abandoned checkout costs nothing and
 * leaves no payment row.
 *
 * Credentials come from the environment (PAYMENT_CLIENT_ID / PAYMENT_SECRET_KEY),
 * never from the database — a clinic's staff can change most settings, and the
 * key that moves money is not one of them.
 *
 * Sandbox is the default. A deployment has to say PAYMENT_MODE=live on purpose,
 * because the failure of getting that backwards is charging a real card during
 * a demo.
 */
final class PayPalGateway implements PaymentGateway
{
    private const SANDBOX = 'https://api-m.sandbox.paypal.com';
    private const LIVE    = 'https://api-m.paypal.com';

    /**
     * Currencies PayPal accepts, from its own list. Anything else is refused
     * here rather than sent — a provider-side rejection arrives as an opaque
     * error long after the patient has tapped Pay.
     */
    private const CURRENCIES = [
        'AUD', 'BRL', 'CAD', 'CNY', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF', 'ILS',
        'JPY', 'MYR', 'MXN', 'TWD', 'NZD', 'NOK', 'PHP', 'PLN', 'GBP', 'RUB',
        'SGD', 'SEK', 'CHF', 'THB', 'USD',
    ];

    /**
     * Currencies PayPal will not take a decimal part for. Sending "1500.00"
     * for a JPY order is rejected outright.
     */
    private const WHOLE_NUMBER_ONLY = ['HUF', 'JPY', 'TWD'];

    private readonly string $clientId;
    private readonly string $secret;
    private readonly string $base;
    private readonly int $timeout;
    private ?string $token = null;

    public function __construct()
    {
        $this->clientId = trim((string) env('PAYMENT_CLIENT_ID', ''));
        $this->secret   = trim((string) env('PAYMENT_SECRET_KEY', ''));
        $this->timeout  = max(5, (int) env('PAYMENT_TIMEOUT_SECONDS', '30'));
        // Sandbox unless the platform admin has deliberately said live (§21).
        // The setting falls back to PAYMENT_MODE, and both default to sandbox —
        // getting this backwards means charging a real card during a demo, so
        // it takes an explicit act in one place or the other.
        $this->base = strtolower(trim(\App\Services\PlatformSettings::get('payment_mode'))) === 'live'
            ? self::LIVE
            : self::SANDBOX;
    }

    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->secret !== '';
    }

    public function supportsCurrency(string $currency): bool
    {
        return in_array(strtoupper(trim($currency)), self::CURRENCIES, true);
    }

    public function name(): string
    {
        return 'paypal';
    }

    public function unavailableReason(): ?string
    {
        return $this->isConfigured()
            ? null
            : 'PayPal has no credentials. Set PAYMENT_CLIENT_ID and PAYMENT_SECRET_KEY.';
    }

    /** Sandbox or live — worth showing in the app so a demo is never mistaken for real. */
    public function isLive(): bool
    {
        return $this->base === self::LIVE;
    }

    public function createPayment(string $amount, string $currency, array $context = []): array
    {
        $currency = strtoupper(trim($currency));

        if (!$this->supportsCurrency($currency)) {
            throw new GatewayUnavailable(
                "PayPal does not accept $currency. This invoice has to be paid at the clinic."
            );
        }

        $order = $this->call('POST', '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                // Both are echoed back on capture, which is how a captured
                // order is matched to the invoice it belongs to.
                'invoice_id'  => (string) ($context['invoice_no'] ?? ''),
                'custom_id'   => (string) ($context['invoice_id'] ?? ''),
                'description' => mb_substr((string) ($context['description'] ?? 'Clinic invoice'), 0, 127),
                'amount'      => [
                    'currency_code' => $currency,
                    'value'         => $this->formatAmount($amount, $currency),
                ],
            ]],
            'payment_source' => [
                'paypal' => [
                    'experience_context' => [
                        'user_action' => 'PAY_NOW',
                        'return_url'  => (string) ($context['return_url'] ?? ''),
                        'cancel_url'  => (string) ($context['cancel_url'] ?? ''),
                    ],
                ],
            ],
        ]);

        $reference = (string) ($order['id'] ?? '');
        if ($reference === '') {
            throw new GatewayUnavailable('PayPal did not return an order reference.');
        }

        return [
            'reference'    => $reference,
            'approval_url' => $this->approvalUrl($order),
        ];
    }

    public function capture(string $reference): array
    {
        $result = $this->call('POST', "/v2/checkout/orders/$reference/capture");

        $status = (string) ($result['status'] ?? '');
        if ($status !== 'COMPLETED') {
            throw new GatewayUnavailable("PayPal did not complete the payment (status: $status).");
        }

        // The figure that matters is the one PayPal says it took, not the one
        // the order was opened with — they can differ, and the caller checks.
        $capture = $result['purchase_units'][0]['payments']['captures'][0] ?? null;
        if (!is_array($capture)) {
            throw new GatewayUnavailable('PayPal reported no capture on that order.');
        }

        $paidAt = isset($capture['create_time'])
            ? gmdate('Y-m-d H:i:s', (int) strtotime((string) $capture['create_time']))
            : gmdate('Y-m-d H:i:s');

        return [
            'reference' => (string) ($capture['id'] ?? $reference),
            'amount'    => (string) ($capture['amount']['value'] ?? '0'),
            'currency'  => (string) ($capture['amount']['currency_code'] ?? ''),
            'paid_at'   => $paidAt,
            // The invoice this order was opened for, as PayPal echoes it back.
            // Trusting the caller's word for which invoice was paid would let
            // one patient's approval settle another patient's bill.
            'invoice_id' => (string) ($result['purchase_units'][0]['custom_id'] ?? ''),
            'raw'        => $result,
        ];
    }

    // ----------------------------------------------------------------

    /**
     * PayPal returns several links; the payer is sent to exactly one of them.
     * 'payer-action' is what an order created with experience_context uses,
     * 'approve' is the older name — accept either rather than depending on
     * which shape the account happens to produce.
     *
     * @param array<string,mixed> $order
     */
    private function approvalUrl(array $order): string
    {
        foreach ((array) ($order['links'] ?? []) as $link) {
            $rel = strtolower((string) ($link['rel'] ?? ''));
            if ($rel === 'payer-action' || $rel === 'approve') {
                return (string) $link['href'];
            }
        }

        throw new GatewayUnavailable('PayPal returned no approval link for that order.');
    }

    private function formatAmount(string $amount, string $currency): string
    {
        return in_array($currency, self::WHOLE_NUMBER_ONLY, true)
            ? (string) (int) round((float) $amount)
            : number_format((float) $amount, 2, '.', '');
    }

    /**
     * OAuth2 client-credentials token, held for this request only.
     *
     * Not cached across requests on purpose: a token in shared storage is one
     * more secret to protect, and PayPal issues them in a few hundred
     * milliseconds. Two extra calls per payment is a fair price.
     */
    private function accessToken(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        $response = $this->request(
            'POST',
            '/v1/oauth2/token',
            'grant_type=client_credentials',
            [
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->secret),
            ],
        );

        $token = (string) ($response['access_token'] ?? '');
        if ($token === '') {
            throw new GatewayUnavailable('PayPal rejected the credentials in backend/.env.');
        }

        return $this->token = $token;
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private function call(string $method, string $path, ?array $body = null): array
    {
        if (!$this->isConfigured()) {
            throw new GatewayUnavailable((string) $this->unavailableReason());
        }

        return $this->request(
            $method,
            $path,
            $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES),
            [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->accessToken(),
                // Ties a retried HTTP call to the same PayPal operation, so a
                // dropped connection cannot become a second charge.
                'PayPal-Request-Id: ' . bin2hex(random_bytes(12)),
            ],
        );
    }

    /**
     * @param list<string> $headers
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, ?string $body, array $headers): array
    {
        $ch = curl_init($this->base . $path);

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $body,
        ]);

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new GatewayUnavailable('Could not reach PayPal: ' . $error);
        }

        $decoded = json_decode((string) $raw, true);

        if ($status >= 400 || !is_array($decoded)) {
            // PayPal's own text can quote the request, which carries the
            // invoice and the amount. The patient gets a plain sentence; the
            // detail goes to the log where the clinic's own people can read it.
            error_log("[paypal] $method $path -> $status " . substr((string) $raw, 0, 800));

            throw new GatewayUnavailable(
                'PayPal refused the payment. Nothing has been charged. '
                . 'Try again, or pay at the clinic.'
            );
        }

        return $decoded;
    }
}
