<?php

namespace Tests\Unit;

use App\Domain\Payments\Exceptions\CyberSourceConfigurationException;
use App\Domain\Payments\Gateways\CyberSource\CyberSourceClientFactory;
use CyberSource\ApiClient;
use CyberSource\Authentication\Core\MerchantConfiguration;
use Tests\TestCase;

class CyberSourceClientFactoryTest extends TestCase
{
    public function test_disabled_cybersource_config_allows_app_boot(): void
    {
        config([
            'cybersource.enabled' => false,
            'cybersource.merchant_id' => '',
            'cybersource.key_id' => '',
            'cybersource.secret_key' => '',
            'cybersource.target_origins' => [],
        ]);

        $factory = app(CyberSourceClientFactory::class);

        $factory->validateEnabledConfiguration();

        $this->assertInstanceOf(CyberSourceClientFactory::class, $factory);
    }

    public function test_enabled_cybersource_config_with_missing_credentials_throws_clear_exception(): void
    {
        config([
            'cybersource.enabled' => true,
            'cybersource.merchant_id' => '',
            'cybersource.key_id' => '',
            'cybersource.secret_key' => '',
            'cybersource.target_origins' => [],
        ]);

        $this->expectException(CyberSourceConfigurationException::class);
        $this->expectExceptionMessage('CyberSource is enabled but missing required configuration: CYBERSOURCE_MERCHANT_ID, CYBERSOURCE_KEY_ID, CYBERSOURCE_SECRET_KEY, CYBERSOURCE_TARGET_ORIGINS.');

        app(CyberSourceClientFactory::class)->validateEnabledConfiguration();
    }

    public function test_enabled_cybersource_config_builds_sdk_objects_without_api_call(): void
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
        ]);

        $factory = app(CyberSourceClientFactory::class);
        $merchantConfig = $factory->merchantConfiguration();
        $apiClient = $factory->make();

        $this->assertInstanceOf(MerchantConfiguration::class, $merchantConfig);
        $this->assertSame('JWT', $merchantConfig->getAuthenticationType());
        $this->assertSame('apitest.cybersource.com', $merchantConfig->getRunEnvironment());
        $this->assertSame('test_merchant', $merchantConfig->getMerchantID());
        $this->assertSame('SHARED_SECRET', $merchantConfig->getJwtKeyType());
        $this->assertSame('test_key_id', $merchantConfig->getApiKeyID());
        $this->assertSame('test_secret', $merchantConfig->getSecretKey());
        $this->assertInstanceOf(ApiClient::class, $apiClient);
    }
}
