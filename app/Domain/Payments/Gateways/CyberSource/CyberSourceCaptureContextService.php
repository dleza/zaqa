<?php

namespace App\Domain\Payments\Gateways\CyberSource;

use App\Models\Payment;
use CyberSource\Api\MicroformIntegrationApi;
use CyberSource\Model\GenerateCaptureContextRequest;
use CyberSource\Model\Microformv2sessionsTransientTokenResponseOptions;

final class CyberSourceCaptureContextService
{
    public function __construct(
        private readonly CyberSourceClientFactory $clientFactory,
        private readonly ?MicroformIntegrationApi $microformApi = null,
    ) {
    }

    /**
     * @return array{
     *     capture_context: string,
     *     payment_id: int|null,
     *     client_version: string,
     *     target_origins: list<string>,
     *     allowed_card_networks: list<string>,
     *     allowed_payment_types: list<string>,
     *     http_status: int|null,
     *     request_id: string|null
     * }
     */
    public function createForPayment(Payment $payment): array
    {
        $this->clientFactory->assertEnabled();

        [$captureContext, $statusCode, $headers] = $this->api()->generateCaptureContext(
            $this->buildCaptureContextRequest($payment),
        );

        return [
            'capture_context' => (string) $captureContext,
            'payment_id' => $payment->getKey() !== null ? (int) $payment->getKey() : null,
            'client_version' => $this->clientVersion(),
            'target_origins' => $this->configList('target_origins'),
            'allowed_card_networks' => $this->configList('allowed_card_networks'),
            'allowed_payment_types' => $this->configList('allowed_payment_types'),
            'http_status' => is_numeric($statusCode) ? (int) $statusCode : null,
            'request_id' => $this->headerValue((array) $headers, 'v-c-request-id'),
        ];
    }

    public function buildCaptureContextRequest(Payment $payment): GenerateCaptureContextRequest
    {
        return new GenerateCaptureContextRequest([
            'clientVersion' => $this->clientVersion(),
            'targetOrigins' => $this->configList('target_origins'),
            'allowedCardNetworks' => $this->configList('allowed_card_networks'),
            'allowedPaymentTypes' => $this->configList('allowed_payment_types'),
            'transientTokenResponseOptions' => new Microformv2sessionsTransientTokenResponseOptions([
                'includeCardPrefix' => false,
            ]),
        ]);
    }

    private function api(): MicroformIntegrationApi
    {
        return $this->microformApi ?? $this->clientFactory->microformApi();
    }

    private function clientVersion(): string
    {
        return trim((string) config('cybersource.microform_client_version', 'v2')) ?: 'v2';
    }

    /**
     * @return list<string>
     */
    private function configList(string $key): array
    {
        return array_values(array_filter(array_map(
            static fn ($value) => trim((string) $value),
            (array) config("cybersource.{$key}", [])
        )));
    }

    private function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) !== 0) {
                continue;
            }

            if (is_array($value)) {
                $value = reset($value);
            }

            $value = trim((string) $value);

            return $value !== '' ? $value : null;
        }

        return null;
    }
}
