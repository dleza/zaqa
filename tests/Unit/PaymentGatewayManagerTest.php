<?php

namespace Tests\Unit;

use App\Domain\Payments\Exceptions\UnsupportedPaymentProviderException;
use App\Domain\Payments\Gateways\CGrate\CGratePaymentGateway;
use App\Domain\Payments\Gateways\CyberSource\CyberSourcePaymentGateway;
use App\Domain\Payments\PaymentGatewayManager;
use App\Domain\Payments\TestPaymentGateway;
use Tests\TestCase;

class PaymentGatewayManagerTest extends TestCase
{
    public function test_cybersource_provider_resolves_to_cybersource_gateway(): void
    {
        $gateway = app(PaymentGatewayManager::class)->gateway('cybersource');

        $this->assertInstanceOf(CyberSourcePaymentGateway::class, $gateway);
    }

    public function test_cgrate_provider_still_resolves_to_cgrate_gateway(): void
    {
        $gateway = app(PaymentGatewayManager::class)->gateway('cgrate');

        $this->assertInstanceOf(CGratePaymentGateway::class, $gateway);
    }

    public function test_test_provider_still_resolves_to_test_gateway(): void
    {
        $gateway = app(PaymentGatewayManager::class)->gateway('test');

        $this->assertInstanceOf(TestPaymentGateway::class, $gateway);
    }

    public function test_blank_provider_still_resolves_to_test_gateway_for_existing_records(): void
    {
        $gateway = app(PaymentGatewayManager::class)->gateway('');

        $this->assertInstanceOf(TestPaymentGateway::class, $gateway);
    }

    public function test_unknown_provider_throws_unsupported_payment_provider_exception(): void
    {
        $this->expectException(UnsupportedPaymentProviderException::class);

        app(PaymentGatewayManager::class)->gateway('unknown-provider');
    }
}
