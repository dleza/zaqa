<?php

namespace App\Domain\Payments\Exceptions;

use RuntimeException;

final class UnsupportedPaymentProviderException extends RuntimeException
{
    public static function forProvider(string $provider): self
    {
        $label = $provider !== '' ? $provider : '(blank)';

        return new self("Unsupported payment provider [{$label}].");
    }
}
