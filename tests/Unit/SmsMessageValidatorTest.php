<?php

namespace Tests\Unit;

use App\Domain\Notifications\Sms\SmsMessageValidator;
use Tests\TestCase;

class SmsMessageValidatorTest extends TestCase
{
    public function test_159_characters_allowed(): void
    {
        $validator = app(SmsMessageValidator::class);
        $message = str_repeat('a', 159);

        $this->assertTrue($validator->isValidLength($message));
    }

    public function test_160_characters_rejected(): void
    {
        $validator = app(SmsMessageValidator::class);
        $message = str_repeat('a', 160);

        $this->expectException(\InvalidArgumentException::class);
        $validator->assertValidLength($message);
    }

    public function test_all_configured_templates_render_under_limit_with_sample_placeholders(): void
    {
        $validator = app(SmsMessageValidator::class);
        $samples = [
            'application_number' => 'ZAQA-2026-999999',
            'code' => '123456',
            'expires_at' => '31 Dec 2026 23:59',
        ];

        foreach (array_keys(config('sms_templates')) as $key) {
            $rendered = $validator->renderTemplate($key, $samples);
            $this->assertLessThanOrEqual(159, mb_strlen($rendered), "Template {$key} exceeds 159 chars.");
        }
    }
}
