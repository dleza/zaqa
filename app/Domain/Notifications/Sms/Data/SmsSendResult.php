<?php

namespace App\Domain\Notifications\Sms\Data;

final class SmsSendResult
{
    /**
     * @param  array<string, mixed>  $sanitizedResponse
     */
    public function __construct(
        public readonly bool $accepted,
        public readonly int $httpStatus,
        public readonly ?bool $providerSuccess,
        public readonly ?string $providerMessage,
        public readonly ?string $providerReference,
        public readonly array $sanitizedResponse,
        public readonly ?string $failureReason = null,
        public readonly bool $transientFailure = false,
    ) {
    }

    public function shouldRetry(): bool
    {
        return $this->transientFailure;
    }
}
