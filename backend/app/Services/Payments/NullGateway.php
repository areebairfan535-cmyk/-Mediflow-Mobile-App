<?php
declare(strict_types=1);

namespace App\Services\Payments;

/**
 * The gateway used when none is configured.
 *
 * This is the expected default, not a fault. A clinic that takes cash at the
 * desk needs no gateway, and §26 puts online payment outside the MVP — so the
 * bills screen shows the balance and says where to pay, exactly as it did
 * before any of this existed. Nothing degrades; one button is absent.
 */
final class NullGateway implements PaymentGateway
{
    public function __construct(
        private readonly string $reason = 'No online payment gateway is configured.',
    ) {
    }

    public function createPayment(string $amount, string $currency, array $context = []): array
    {
        throw new GatewayUnavailable($this->reason . ' Pay at the clinic reception instead.');
    }

    public function capture(string $reference): array
    {
        throw new GatewayUnavailable($this->reason);
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function supportsCurrency(string $currency): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'none';
    }

    public function unavailableReason(): ?string
    {
        return $this->reason;
    }
}
