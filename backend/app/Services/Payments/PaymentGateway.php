<?php
declare(strict_types=1);

namespace App\Services\Payments;

/**
 * The online payment contract — §13's Strategy Pattern applied to §7 billing.
 *
 * §7 asks for online payment but names no provider, and the payments table was
 * built for that: `gateway`, `gateway_ref` and `gateway_payload` are generic on
 * purpose. So which company actually moves the money is a configuration choice,
 * and adding a second one is a class plus a line in PaymentGateways::resolve().
 *
 * Two rules every implementation must keep, because the money is real:
 *
 *   - The amount is never taken from the caller. A gateway is handed the
 *     figure the invoice says is outstanding, and capture() must report back
 *     what the provider actually took so the two can be compared before a
 *     payment row is written.
 *   - A reference is captured at most once. The same approval landing twice —
 *     a refreshed browser, a retried webhook — must not become two payments.
 *     The uniqueness check lives above this interface, on gateway_ref.
 */
interface PaymentGateway
{
    /**
     * Begin a payment and return where to send the payer.
     *
     * @param string $amount    decimal string, e.g. "1500.00"
     * @param string $currency  ISO 4217, e.g. "PKR"
     * @param array<string,mixed> $context invoice_no, description, return_url, cancel_url
     * @return array{reference: string, approval_url: string}
     * @throws GatewayUnavailable
     */
    public function createPayment(string $amount, string $currency, array $context = []): array;

    /**
     * Take the money the payer approved.
     *
     * Returns what the provider says it actually captured — not what was asked
     * for. The caller compares the two.
     *
     * `invoice_id` is whatever was handed to createPayment() as context, given
     * back by the provider. The caller trusts that over anything the client
     * says, so one patient's approval cannot settle another patient's bill.
     *
     * @return array{reference: string, amount: string, currency: string, paid_at: string, invoice_id: string, raw: array<string,mixed>}
     * @throws GatewayUnavailable
     */
    public function capture(string $reference): array;

    /** Can this gateway take a payment right now? */
    public function isConfigured(): bool;

    /** Can it handle this currency? Refusing early beats a provider error. */
    public function supportsCurrency(string $currency): bool;

    /** Stored in payments.gateway and shown in the app. */
    public function name(): string;

    /** Why it cannot be used, when isConfigured() is false. */
    public function unavailableReason(): ?string;
}
