<?php

namespace App\Domain\Payments;

use App\Enums\PaymentMethod;
use App\Models\Payment;

interface PaymentGateway
{
    public function providerKey(): string;

    /**
     * Initiate an online payment (card or mobile money).
     *
     * @param array<string, mixed> $payload
     * @return array{redirect_url?: string, provider_reference: string, provider_transaction_id?: string, raw_payload?: array<string, mixed>}
     */
    public function initiate(Payment $payment, PaymentMethod $method, array $payload): array;

    /**
     * Verify a provider reference and return a normalized status.
     *
     * @param array<string, mixed> $payload
     * @return array{status: string, provider_transaction_id?: string, raw_payload?: array<string, mixed>}
     */
    public function verify(string $providerReference, array $payload): array;
}

