<?php
declare(strict_types=1);

namespace App\Services\Payments;

use App\Core\HttpException;

/**
 * The payment gateway could not be used.
 *
 * 503, not 500: the clinic's own billing is fine — the invoice, the balance
 * and the ledger are all intact — and an optional external provider is not.
 * Paying at reception has never stopped working, so the app shows this and
 * points there rather than treating it as a broken system.
 */
final class GatewayUnavailable extends HttpException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 503, 'gateway_unavailable');
    }
}
