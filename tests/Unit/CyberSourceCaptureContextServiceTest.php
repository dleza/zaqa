<?php

namespace Tests\Unit;

use App\Domain\Payments\Gateways\CyberSource\CyberSourceCaptureContextService;
use App\Domain\Payments\Gateways\CyberSource\CyberSourceClientFactory;
use App\Enums\PaymentMethod;
use App\Models\Payment;
use CyberSource\Api\MicroformIntegrationApi;
use CyberSource\Model\GenerateCaptureContextRequest;
use Tests\TestCase;

class CyberSourceCaptureContextServiceTest extends TestCase
{
    public function test_builds_capture_context_request_from_config(): void
    {
        $this->enableCyberSourceConfig();

        $service = new CyberSourceCaptureContextService(app(CyberSourceClientFactory::class));
        $request = $service->buildCaptureContextRequest($this->payment());

        $this->assertSame('2.0', $request->getClientVersion());
        $this->assertSame(['https://example.test'], $request->getTargetOrigins());
        $this->assertSame(['VISA', 'MASTERCARD'], $request->getAllowedCardNetworks());
        $this->assertSame(['CARD'], $request->getAllowedPaymentTypes());
        $this->assertFalse($request->getTransientTokenResponseOptions()->getIncludeCardPrefix());
    }

    public function test_create_for_payment_uses_mocked_microform_api_without_network_call(): void
    {
        $this->enableCyberSourceConfig();

        $api = $this->createMock(MicroformIntegrationApi::class);
        $api->expects($this->once())
            ->method('generateCaptureContext')
            ->with($this->callback(fn (GenerateCaptureContextRequest $request): bool => $request->getTargetOrigins() === ['https://example.test']))
            ->willReturn([
                'capture-context-jwt',
                201,
                ['v-c-request-id' => ['capture-request-123']],
            ]);

        $service = new CyberSourceCaptureContextService(app(CyberSourceClientFactory::class), $api);

        $result = $service->createForPayment($this->payment());

        $this->assertSame('capture-context-jwt', $result['capture_context']);
        $this->assertSame(321, $result['payment_id']);
        $this->assertSame(['https://example.test'], $result['target_origins']);
        $this->assertSame(['VISA', 'MASTERCARD'], $result['allowed_card_networks']);
        $this->assertSame(201, $result['http_status']);
        $this->assertSame('capture-request-123', $result['request_id']);
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
            'cybersource.allowed_card_networks' => ['VISA', 'MASTERCARD'],
            'cybersource.allowed_payment_types' => ['CARD'],
        ]);
    }
}
