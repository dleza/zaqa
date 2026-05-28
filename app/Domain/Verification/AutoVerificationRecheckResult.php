<?php

namespace App\Domain\Verification;

final class AutoVerificationRecheckResult
{
    public function __construct(
        public readonly bool $queued,
        public readonly string $message,
    ) {
    }
}
