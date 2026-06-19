<?php

namespace App\Domain\Payments\Gateways\CyberSource;

use App\Models\Payment;
use CyberSource\Api\PaymentsApi;
use CyberSource\ApiException;
use CyberSource\Model\CreatePaymentRequest;
use CyberSource\Model\Ptsv2paymentsClientReferenceInformation;
use CyberSource\Model\Ptsv2paymentsOrderInformation;
use CyberSource\Model\Ptsv2paymentsOrderInformationAmountDetails;
use CyberSource\Model\Ptsv2paymentsProcessingInformation;
use CyberSource\Model\Ptsv2paymentsTokenInformation;

final class CyberSourcePaymentService
{
    public function __construct(
        private readonly CyberSourceClientFactory $clientFactory,
        private readonly CyberSourceStatusMapper $statusMapper,
        private readonly CyberSourcePayloadSanitizer $payloadSanitizer,
        private readonly ?PaymentsApi $paymentsApi = null,
    ) {
    }

    /**
     * @return array{status: string, provider_transaction_id: string|null, raw_payload: array<string, mixed>}
     */
    public function chargeTransientToken(Payment $payment, string $transientTokenJwt): array
    {
        $this->clientFactory->assertEnabled();

        try {
            [$response, $statusCode, $headers] = $this->api()->createPayment(
                $this->buildPaymentRequest($payment, $transientTokenJwt),
            );

            return $this->verificationResultFromResponse($response, (int) $statusCode, (array) $headers);
        } catch (ApiException $exception) {
            return $this->verificationResultFromException($exception);
        }
    }

    public function buildPaymentRequest(Payment $payment, string $transientTokenJwt): CreatePaymentRequest
    {
        return new CreatePaymentRequest([
            'clientReferenceInformation' => new Ptsv2paymentsClientReferenceInformation([
                'code' => $this->clientReferenceCode($payment),
            ]),
            'processingInformation' => new Ptsv2paymentsProcessingInformation([
                'capture' => (bool) config('cybersource.capture', true),
            ]),
            'orderInformation' => new Ptsv2paymentsOrderInformation([
                'amountDetails' => new Ptsv2paymentsOrderInformationAmountDetails([
                    'totalAmount' => $this->formatAmount((int) $payment->amount_cents),
                    'currency' => strtoupper((string) ($payment->currency ?: 'ZMW')),
                ]),
            ]),
            'tokenInformation' => new Ptsv2paymentsTokenInformation([
                'transientTokenJwt' => $transientTokenJwt,
            ]),
        ]);
    }

    /**
     * @return array{status: string, provider_transaction_id: string|null, raw_payload: array<string, mixed>}
     */
    public function verificationResultFromResponse(mixed $response, int $httpStatus, array $headers = []): array
    {
        $normalizedStatus = $this->statusMapper->toNormalizedStatus($response);

        return [
            'status' => $this->statusMapper->toGatewayVerificationStatus($response),
            'provider_transaction_id' => $this->responseId($response),
            'raw_payload' => $this->payloadSanitizer->sanitize([
                'cybersource' => [
                    'id' => $this->responseId($response),
                    'status' => $this->extractString($response, 'status', 'getStatus'),
                    'payment_status' => $normalizedStatus,
                    'message' => $this->extractString($response, 'message', 'getMessage'),
                    'submit_time_utc' => $this->extractString($response, 'submitTimeUtc', 'getSubmitTimeUtc'),
                    'reconciliation_id' => $this->extractString($response, 'reconciliationId', 'getReconciliationId'),
                    'request_id' => $this->headerValue($headers, 'v-c-request-id'),
                    'http_status' => $httpStatus,
                    'processor' => $this->processorPayload($response),
                    'error' => $this->errorPayload($response),
                ],
            ]),
        ];
    }

    /**
     * @return array{status: string, provider_transaction_id: null, raw_payload: array<string, mixed>}
     */
    private function verificationResultFromException(ApiException $exception): array
    {
        $response = $exception->getResponseObject() ?: $this->decodeResponseBody($exception->getResponseBody());

        return [
            'status' => $this->statusMapper->toGatewayVerificationStatus($response),
            'provider_transaction_id' => null,
            'raw_payload' => $this->payloadSanitizer->sanitize([
                'cybersource' => [
                    'status' => $this->extractString($response, 'status', 'getStatus'),
                    'payment_status' => $this->statusMapper->toNormalizedStatus($response),
                    'reason' => $this->extractString($response, 'reason', 'getReason'),
                    'message' => $this->extractString($response, 'message', 'getMessage') ?: $exception->getMessage(),
                    'request_id' => $this->headerValue((array) $exception->getResponseHeaders(), 'v-c-request-id'),
                    'http_status' => $exception->getCode(),
                ],
            ]),
        ];
    }

    private function api(): PaymentsApi
    {
        return $this->paymentsApi ?? $this->clientFactory->paymentsApi();
    }

    private function clientReferenceCode(Payment $payment): string
    {
        $providerReference = trim((string) $payment->provider_reference);
        if ($providerReference !== '') {
            return $providerReference;
        }

        $invoiceId = (int) ($payment->invoice_id ?? 0);
        $paymentId = (int) ($payment->id ?? 0);

        return 'ZAQA-PAY-'.$paymentId.'-INV-'.$invoiceId;
    }

    private function formatAmount(int $amountCents): string
    {
        return number_format($amountCents / 100, 2, '.', '');
    }

    private function responseId(mixed $response): ?string
    {
        return $this->extractString($response, 'id', 'getId')
            ?: $this->extractString($this->processorInformation($response), 'transactionId', 'getTransactionId');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function processorPayload(mixed $response): ?array
    {
        $processor = $this->processorInformation($response);
        if ($processor === null) {
            return null;
        }

        return [
            'transaction_id' => $this->extractString($processor, 'transactionId', 'getTransactionId'),
            'approval_code' => $this->extractString($processor, 'approvalCode', 'getApprovalCode'),
            'response_code' => $this->extractString($processor, 'responseCode', 'getResponseCode'),
            'response_details' => $this->extractString($processor, 'responseDetails', 'getResponseDetails'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function errorPayload(mixed $response): ?array
    {
        $error = null;

        if (is_array($response)) {
            $error = $response['errorInformation'] ?? null;
        } elseif (is_object($response) && method_exists($response, 'getErrorInformation')) {
            $error = $response->getErrorInformation();
        }

        if ($error === null) {
            return null;
        }

        return [
            'reason' => $this->extractString($error, 'reason', 'getReason'),
            'message' => $this->extractString($error, 'message', 'getMessage'),
        ];
    }

    private function processorInformation(mixed $response): mixed
    {
        if (is_array($response)) {
            return $response['processorInformation'] ?? null;
        }

        if (is_object($response) && method_exists($response, 'getProcessorInformation')) {
            return $response->getProcessorInformation();
        }

        return null;
    }

    private function extractString(mixed $value, string $arrayKey, string $getter): ?string
    {
        if (is_array($value) && array_key_exists($arrayKey, $value)) {
            $extracted = trim((string) $value[$arrayKey]);

            return $extracted !== '' ? $extracted : null;
        }

        if (is_object($value) && method_exists($value, $getter)) {
            $extracted = trim((string) $value->{$getter}());

            return $extracted !== '' ? $extracted : null;
        }

        return null;
    }

    private function decodeResponseBody(mixed $body): mixed
    {
        if (is_string($body) && trim($body) !== '') {
            $decoded = json_decode($body, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : ['message' => $body];
        }

        return $body;
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
