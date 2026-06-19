<?php

namespace App\Domain\Payments\Gateways\CyberSource;

final class CyberSourceStatusMapper
{
    public function toNormalizedStatus(?string $gatewayStatus, ?string $reasonCode = null): string
    {
        // TODO: Map CyberSource statuses and reason codes conservatively.
        return 'pending';
    }
}
