<?php

namespace App\Domain\Payments;

use App\Enums\PaymentMethod;
use App\Models\Payment;
use Illuminate\Support\Str;

class TestPaymentGateway implements PaymentGateway
{
    public function providerKey(): string
    {
        return 'test';
    }

    public function initiate(Payment $payment, PaymentMethod $method, array $payload): array
    {
        $ref = 'TEST-'.Str::upper(Str::random(12));

        return [
            'redirect_url' => route('payments.test.redirect', [
                'payment' => $payment->id,
                'ref' => $ref,
            ]),
            'provider_reference' => $ref,
            'provider_transaction_id' => null,
            'raw_payload' => [
                'method' => $method->value,
                'note' => 'Test gateway initiation.',
            ],
        ];
    }

    public function verify(string $providerReference, array $payload): array
    {
        $status = (string) ($payload['status'] ?? 'success');
        $normalized = in_array($status, ['success', 'confirmed'], true) ? 'confirmed' : 'failed';

        return [
            'status' => $normalized,
            'provider_transaction_id' => (string) ($payload['tx'] ?? ''),
            'raw_payload' => $payload,
        ];
    }
}

