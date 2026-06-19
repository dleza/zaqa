<?php

namespace App\Domain\Payments\Gateways\CyberSource;

final class CyberSourcePayloadSanitizer
{
    public function sanitize(array $payload): array
    {
        // TODO: Keep only non-sensitive CyberSource response metadata.
        return [];
    }
}
