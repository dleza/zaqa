<?php

namespace App\Domain\Payments\Gateways\CyberSource;

use LogicException;

final class CyberSourceClientFactory
{
    public function make(): object
    {
        // TODO: Create the official CyberSource SDK client after the SDK is added.
        throw new LogicException('CyberSource SDK client creation is not implemented yet.');
    }
}
