<?php

namespace App\Domain\Payments\Gateways\CyberSource;

use App\Models\Payment;
use LogicException;

final class CyberSourcePaymentService
{
    public function chargeTransientToken(Payment $payment, string $transientTokenJwt): array
    {
        // TODO: Submit the transient token to CyberSource REST Payments API.
        throw new LogicException('CyberSource transient-token payment is not implemented yet.');
    }
}
