<?php

namespace App\Domain\Payments\Gateways\CyberSource;

use App\Domain\Payments\PaymentGateway;
use App\Enums\PaymentMethod;
use App\Models\Payment;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CyberSourcePaymentGateway implements PaymentGateway
{
    public function __construct(
        private readonly CyberSourceCaptureContextService $captureContextService,
        private readonly CyberSourcePaymentService $paymentService,
        private readonly CyberSourceStatusMapper $statusMapper,
        private readonly CyberSourcePayloadSanitizer $payloadSanitizer,
    ) {
    }

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
                'implemented' => true,
            ],
        ];
    }

    public function verify(string $providerReference, array $payload): array
    {
        return [
            'status' => $this->statusMapper->toGatewayVerificationStatus($payload),
            'provider_transaction_id' => $this->providerTransactionId($payload),
            'raw_payload' => $this->payloadSanitizer->sanitize([
                'gateway' => $this->providerKey(),
                'provider_reference' => $providerReference,
                'phase' => 'verification',
                'cybersource' => $payload,
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function createCaptureContext(Payment $payment): array
    {
        $this->assertCardPayment($payment);

        return $this->captureContextService->createForPayment($payment);
    }

    /**
     * @return array{status: string, provider_transaction_id: string|null, raw_payload: array<string, mixed>}
     */
    public function chargeTransientToken(Payment $payment, string $transientTokenJwt): array
    {
        $this->assertCardPayment($payment);

        return $this->paymentService->chargeTransientToken($payment, $transientTokenJwt);
    }

    private function generateProviderReference(Payment $payment): string
    {
        $invoiceId = (int) ($payment->invoice_id ?? 0);
        $paymentId = (int) ($payment->id ?? 0);

        return 'CYBS-'.$invoiceId.'-'.$paymentId.'-'.Str::upper(Str::random(10));
    }

    private function assertCardPayment(Payment $payment): void
    {
        if ($payment->method !== PaymentMethod::Card) {
            throw new InvalidArgumentException('CyberSource gateway only supports card payments.');
        }
    }

    private function providerTransactionId(array $payload): ?string
    {
        foreach (['id', 'provider_transaction_id', 'transaction_id'] as $key) {
            $value = trim((string) ($payload[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        $processorId = trim((string) data_get($payload, 'processorInformation.transactionId', ''));

        return $processorId !== '' ? $processorId : null;
    }
}
