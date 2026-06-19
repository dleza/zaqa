<?php

namespace App\Domain\Payments\Gateways\CyberSource;

use App\Domain\Payments\PaymentGateway;
use App\Enums\PaymentMethod;
use App\Models\Payment;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CyberSourcePaymentGateway implements PaymentGateway
{
    public function providerKey(): string
    {
        return 'cybersource';
    }

    public function initiate(Payment $payment, PaymentMethod $method, array $payload): array
    {
        if ($method !== PaymentMethod::Card) {
            throw new InvalidArgumentException('CyberSource gateway only supports card payments.');
        }

        $providerReference = $this->generateProviderReference($payment);

        return [
            'redirect_url' => null,
            'provider_reference' => $providerReference,
            'provider_transaction_id' => null,
            'raw_payload' => [
                'gateway' => $this->providerKey(),
                'phase' => 'initiated',
                'implemented' => false,
            ],
        ];
    }

    public function verify(string $providerReference, array $payload): array
    {
        return [
            'status' => 'pending',
            'provider_transaction_id' => null,
            'raw_payload' => [
                'gateway' => $this->providerKey(),
                'provider_reference' => $providerReference,
                'phase' => 'verification_placeholder',
                'implemented' => false,
            ],
        ];
    }

    private function generateProviderReference(Payment $payment): string
    {
        $invoiceId = (int) ($payment->invoice_id ?? 0);
        $paymentId = (int) ($payment->id ?? 0);

        return 'CYBS-'.$invoiceId.'-'.$paymentId.'-'.Str::upper(Str::random(10));
    }
}
