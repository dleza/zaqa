<?php

namespace Tests\Unit;

use App\Domain\Payments\Gateways\CyberSource\CyberSourceClientFactory;
use App\Domain\Payments\Gateways\CyberSource\CyberSourcePaymentService;
use App\Domain\Payments\Gateways\CyberSource\CyberSourcePayloadSanitizer;
use App\Domain\Payments\Gateways\CyberSource\CyberSourceStatusMapper;
use App\Enums\PaymentMethod;
use App\Models\Payment;
use CyberSource\Api\PaymentsApi;
use CyberSource\Model\CreatePaymentRequest;
use CyberSource\Model\PtsV2PaymentsPost201Response;
use CyberSource\Model\PtsV2PaymentsPost201ResponseProcessorInformation;
use Tests\TestCase;

class CyberSourcePaymentServiceTest extends TestCase
{
    public function test_builds_transient_token_payment_request_from_payment(): void
    {
        config(['cybersource.capture' => true]);

        $service = $this->service();
        $payment = $this->payment();

        $request = $service->buildPaymentRequest($payment, 'header.payload.signature');

        $this->assertSame('CYBS-88-321-ABCDEF1234', $request->getClientReferenceInformation()->getCode());
        $this->assertTrue($request->getProcessingInformation()->getCapture());
        $this->assertSame('125.50', $request->getOrderInformation()->getAmountDetails()->getTotalAmount());
        $this->assertSame('ZMW', $request->getOrderInformation()->getAmountDetails()->getCurrency());
        $this->assertSame('header.payload.signature', $request->getTokenInformation()->getTransientTokenJwt());
        $this->assertNull($request->getPaymentInformation());
    }

    public function test_charge_transient_token_maps_successful_sdk_response_without_network_call(): void
    {
        $this->enableCyberSourceConfig();

        $api = $this->createMock(PaymentsApi::class);
        $api->expects($this->once())
            ->method('createPayment')
            ->with($this->callback(function (CreatePaymentRequest $request): bool {
                return $request->getClientReferenceInformation()->getCode() === 'CYBS-88-321-ABCDEF1234'
                    && $request->getTokenInformation()->getTransientTokenJwt() === 'header.payload.signature';
            }))
            ->willReturn([
                new PtsV2PaymentsPost201Response([
                    'id' => 'cybs-payment-id',
                    'status' => 'AUTHORIZED',
                    'reconciliationId' => 'recon-123',
                    'processorInformation' => new PtsV2PaymentsPost201ResponseProcessorInformation([
                        'transactionId' => 'processor-tx-123',
                        'approvalCode' => 'AUTH123',
                        'responseCode' => '100',
                    ]),
                ]),
                201,
                ['v-c-request-id' => ['request-123']],
            ]);

        $service = $this->service($api);

        $result = $service->chargeTransientToken($this->payment(), 'header.payload.signature');

        $this->assertSame('confirmed', $result['status']);
        $this->assertSame('cybs-payment-id', $result['provider_transaction_id']);
        $this->assertSame('confirmed', $result['raw_payload']['cybersource']['payment_status']);
        $this->assertSame('recon-123', $result['raw_payload']['cybersource']['reconciliation_id']);
        $this->assertSame('request-123', $result['raw_payload']['cybersource']['request_id']);
        $this->assertSame('AUTH123', $result['raw_payload']['cybersource']['processor']['approval_code']);
        $this->assertStringNotContainsString('header.payload.signature', json_encode($result['raw_payload']));
    }

    private function service(?PaymentsApi $api = null): CyberSourcePaymentService
    {
        return new CyberSourcePaymentService(
            app(CyberSourceClientFactory::class),
            new CyberSourceStatusMapper(),
            new CyberSourcePayloadSanitizer(),
            $api,
        );
    }

    private function payment(): Payment
    {
        $payment = new Payment([
            'invoice_id' => 88,
            'method' => PaymentMethod::Card,
            'currency' => 'ZMW',
            'amount_cents' => 12550,
            'provider' => 'cybersource',
            'provider_reference' => 'CYBS-88-321-ABCDEF1234',
        ]);

        $payment->id = 321;

        return $payment;
    }

    private function enableCyberSourceConfig(): void
    {
        config([
            'cybersource.enabled' => true,
            'cybersource.run_environment' => 'apitest.cybersource.com',
            'cybersource.merchant_id' => 'test_merchant',
            'cybersource.key_id' => 'test_key_id',
            'cybersource.secret_key' => 'test_secret',
            'cybersource.auth_type' => 'JWT',
            'cybersource.jwt_key_type' => 'SHARED_SECRET',
            'cybersource.target_origins' => ['https://example.test'],
            'cybersource.capture' => true,
        ]);
    }
}
