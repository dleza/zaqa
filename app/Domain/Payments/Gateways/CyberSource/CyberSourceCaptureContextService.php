<?php

namespace App\Domain\Payments\Gateways\CyberSource;

use App\Models\Payment;
use LogicException;

final class CyberSourceCaptureContextService
{
    public function createForPayment(Payment $payment): array
    {
        // TODO: Create a CyberSource Microform/Flex capture context using REST.
        throw new LogicException('CyberSource capture context creation is not implemented yet.');
    }
}
