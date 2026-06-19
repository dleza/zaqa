<?php

namespace Tests\Unit;

use App\Domain\Payments\Gateways\CyberSource\CyberSourcePayloadSanitizer;
use Tests\TestCase;

class CyberSourcePayloadSanitizerTest extends TestCase
{
    public function test_removes_transient_token_and_raw_card_fields(): void
    {
        $sanitizer = new CyberSourcePayloadSanitizer();

        $sanitized = $sanitizer->sanitize([
            'id' => 'cybs-payment-id',
            'transientTokenJwt' => 'header.payload.signature',
            'paymentInformation' => [
                'card' => [
                    'number' => '4111111111111111',
                    'securityCode' => '123',
                    'expirationMonth' => '12',
                    'expirationYear' => '2030',
                ],
                'tokenizedCard' => [
                    'number' => '5555555555554444',
                    'cryptogram' => 'sensitive',
                ],
            ],
            'processor' => [
                'response_code' => '100',
            ],
        ]);

        $this->assertSame('cybs-payment-id', $sanitized['id']);
        $this->assertArrayNotHasKey('transientTokenJwt', $sanitized);
        $this->assertArrayNotHasKey('card', $sanitized['paymentInformation']);
        $this->assertArrayNotHasKey('tokenizedCard', $sanitized['paymentInformation']);
        $this->assertSame('100', $sanitized['processor']['response_code']);
    }

    public function test_removes_card_like_scalar_values(): void
    {
        $sanitizer = new CyberSourcePayloadSanitizer();

        $sanitized = $sanitizer->sanitize([
            'safe' => 'keep-me',
            'nested' => [
                'unexpected_value' => '4111 1111 1111 1111',
            ],
        ]);

        $this->assertSame('keep-me', $sanitized['safe']);
        $this->assertArrayNotHasKey('unexpected_value', $sanitized['nested']);
    }
}
