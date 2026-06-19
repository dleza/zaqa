<?php

namespace App\Domain\Payments\Exceptions;

use RuntimeException;

final class CyberSourceConfigurationException extends RuntimeException
{
    /**
     * @param  list<string>  $missing
     */
    public static function missingRequiredValues(array $missing): self
    {
        return new self('CyberSource is enabled but missing required configuration: '.implode(', ', $missing).'.');
    }
}
