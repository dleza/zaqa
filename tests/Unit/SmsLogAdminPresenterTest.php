<?php

namespace Tests\Unit;

use App\Domain\Notifications\Sms\SmsLogAdminPresenter;
use Tests\TestCase;

class SmsLogAdminPresenterTest extends TestCase
{
    private SmsLogAdminPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->presenter = app(SmsLogAdminPresenter::class);
    }

    public function test_masks_phone_numbers_for_admin_display(): void
    {
        $this->assertSame('********0001', $this->presenter->maskPhone('260977000001'));
        $this->assertSame('********0001', $this->presenter->maskPhone('[260977000001]'));
    }

    public function test_redacts_otp_code_from_activation_template_messages(): void
    {
        $message = 'ZAQA OTP: 483920. Expires 13 Jun 2026 15:00.';

        $redacted = $this->presenter->messageBodyForAdmin('activation_otp', $message);

        $this->assertSame('ZAQA OTP: [redacted]. Expires 13 Jun 2026 15:00.', $redacted);
        $this->assertTrue($this->presenter->isMessageBodyRedacted('activation_otp'));
    }

    public function test_leaves_non_sensitive_templates_unchanged(): void
    {
        $message = 'ZAQA: Payment confirmed for ZAQA-2026-000001. Continue in the portal.';

        $this->assertSame($message, $this->presenter->messageBodyForAdmin('payment_approved', $message));
        $this->assertFalse($this->presenter->isMessageBodyRedacted('payment_approved'));
    }
}
