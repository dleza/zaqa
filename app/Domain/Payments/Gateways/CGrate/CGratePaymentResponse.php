<?php

namespace App\Domain\Payments\Gateways\CGrate;

final class CGratePaymentResponse
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly ?int $responseCode,
        public readonly string $responseMessage,
        public readonly ?string $paymentId,
        public readonly array $raw = [],
    ) {
    }

    public function isSuccessfulRequest(): bool
    {
        return $this->responseCode === 0;
    }

    public function isPending(): bool
    {
        return in_array($this->responseCode, [206, 8, 17, 106], true);
    }

    public function isApproved(): bool
    {
        if (in_array($this->responseCode, [207, 226], true)) {
            return true;
        }

        // Sandbox/test environments may return responseCode=0 with a paymentID on query
        // when the customer payment has completed (production often uses 207/226 instead).
        return $this->isSuccessfulQueryPayment();
    }

    private function isSuccessfulQueryPayment(): bool
    {
        if ($this->responseCode !== 0) {
            return false;
        }

        $operation = (string) ($this->raw['operation'] ?? '');
        if ($operation !== 'queryCustomerPayment') {
            return false;
        }

        return trim((string) ($this->paymentId ?? '')) !== '';
    }

    public function isRejected(): bool
    {
        return $this->responseCode === 208;
    }

    public function isFailed(): bool
    {
        return in_array($this->responseCode, [7, 210, 214], true);
    }

    public function isUnknown(): bool
    {
        return in_array($this->responseCode, [12, 213], true);
    }

    public function isConfigOrAuthError(): bool
    {
        return in_array($this->responseCode, [11, 23, 24, 25, 26, 301, 302, 303], true);
    }

    public function isTerminal(): bool
    {
        return $this->isApproved() || $this->isRejected() || $this->isFailed();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'response_code' => $this->responseCode,
            'response_message' => $this->responseMessage,
            'payment_id' => $this->paymentId,
            'raw' => $this->raw,
        ];
    }
}

