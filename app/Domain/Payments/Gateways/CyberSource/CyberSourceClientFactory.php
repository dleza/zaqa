<?php

namespace App\Domain\Payments\Gateways\CyberSource;

use App\Domain\Payments\Exceptions\CyberSourceConfigurationException;
use CyberSource\ApiClient;
use CyberSource\Authentication\Core\MerchantConfiguration;
use CyberSource\Configuration;

final class CyberSourceClientFactory
{
    public function make(): ApiClient
    {
        return new ApiClient(
            Configuration::getDefaultConfiguration(),
            $this->merchantConfiguration(),
        );
    }

    public function merchantConfiguration(): MerchantConfiguration
    {
        $this->validateEnabledConfiguration();

        $config = new MerchantConfiguration();
        $config->setAuthenticationType($this->configString('auth_type', 'JWT'));
        $config->setRunEnvironment($this->configString('run_environment', 'apitest.cybersource.com'));
        $config->setMerchantID($this->configString('merchant_id'));
        $config->setJwtKeyType($this->configString('jwt_key_type', 'SHARED_SECRET'));
        $config->setApiKeyID($this->configString('key_id'));
        $config->setSecretKey($this->configString('secret_key'));

        return $config;
    }

    public function validateEnabledConfiguration(): void
    {
        if (! (bool) config('cybersource.enabled', false)) {
            return;
        }

        $missing = [];

        foreach ([
            'merchant_id' => 'CYBERSOURCE_MERCHANT_ID',
            'key_id' => 'CYBERSOURCE_KEY_ID',
            'secret_key' => 'CYBERSOURCE_SECRET_KEY',
        ] as $key => $label) {
            if ($this->configString($key) === '') {
                $missing[] = $label;
            }
        }

        if ($this->configList('target_origins') === []) {
            $missing[] = 'CYBERSOURCE_TARGET_ORIGINS';
        }

        if ($missing !== []) {
            throw CyberSourceConfigurationException::missingRequiredValues($missing);
        }
    }

    private function configString(string $key, string $default = ''): string
    {
        return trim((string) config("cybersource.{$key}", $default));
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
}
