<?php

namespace Tests\Unit;

use App\Domain\Notifications\Sms\SmsPhoneNormalizer;
use Tests\TestCase;

class SmsPhoneNormalizerTest extends TestCase
{
    public function test_local_number_normalizes_for_zamtel(): void
    {
        $normalizer = app(SmsPhoneNormalizer::class);

        $this->assertSame('[260977000001]', $normalizer->normalizeForZamtel('0977000001'));
    }

    public function test_international_number_normalizes_for_zamtel(): void
    {
        $normalizer = app(SmsPhoneNormalizer::class);

        $this->assertSame('[260977000001]', $normalizer->normalizeForZamtel('+260977000001'));
        $this->assertSame('[260977000001]', $normalizer->normalizeForZamtel('260977000001'));
    }

    public function test_invalid_number_is_rejected(): void
    {
        $normalizer = app(SmsPhoneNormalizer::class);

        $this->assertFalse($normalizer->isValid('12345'));
    }
}
