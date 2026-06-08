<?php

namespace Tests\Unit;

use App\Domain\Notifications\OutboundMailService;
use App\Domain\Notifications\OutboundSmsService;
use App\Mail\ActivationEmailMail;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OutboundNotificationServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_outbound_mail_service_queues_without_throwing_on_failure(): void
    {
        Mail::fake();

        $service = app(OutboundMailService::class);
        $result = $service->queue(
            mailable: new ActivationEmailMail(
                recipientName: 'Jane Doe',
                activationUrl: 'https://example.test/activate',
                expiresAt: CarbonImmutable::now()->addHour(),
            ),
            to: 'jane@example.test',
            logContext: [
                'user_id' => null,
                'application_id' => null,
                'email' => 'jane@example.test',
                'subject' => 'Activate your ZAQA account',
                'template_key' => 'activation_email',
            ],
        );

        $this->assertTrue($result);
        Mail::assertQueued(ActivationEmailMail::class);
        $this->assertDatabaseHas('email_logs', [
            'email' => 'jane@example.test',
            'template_key' => 'activation_email',
            'status' => 'queued',
        ]);
    }

    public function test_outbound_sms_service_logs_without_throwing(): void
    {
        config(['services.sms.provider' => 'log']);

        $service = app(OutboundSmsService::class);
        $result = $service->send(
            phone: '0973936164',
            message: 'Test SMS',
            messageType: 'test_message',
        );

        $this->assertTrue($result);
        $this->assertDatabaseHas('sms_logs', [
            'phone_number' => '0973936164',
            'message_type' => 'test_message',
            'status' => 'sent',
        ]);
    }

    public function test_outbound_sms_service_marks_failed_for_unsupported_provider_without_throwing(): void
    {
        config(['services.sms.provider' => 'unsupported']);

        $service = app(OutboundSmsService::class);
        $result = $service->send(
            phone: '0973936164',
            message: 'Test SMS',
            messageType: 'test_message',
        );

        $this->assertFalse($result);
        $this->assertDatabaseHas('sms_logs', [
            'phone_number' => '0973936164',
            'status' => 'failed',
        ]);
    }
}
