<?php

namespace Tests\Unit;

use App\Domain\Notifications\OutboundMailService;
use App\Domain\Notifications\OutboundSmsService;
use App\Jobs\Notifications\SendSmsJob;
use App\Mail\ActivationEmailMail;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OutboundNotificationServicesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

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

    public function test_activation_otp_queues_through_configured_sms_pipeline(): void
    {
        Queue::fake();

        config(['sms.enabled' => true, 'sms.provider' => 'zamtel']);
        app(\App\Domain\Notifications\Sms\SmsBalanceService::class)->credit(5, 'seed');

        $service = app(OutboundSmsService::class);
        $result = $service->queueTemplate(
            templateKey: 'activation_otp',
            placeholders: [
                'code' => '123456',
                'expires_at' => '13 Jun 2026 15:00',
            ],
            phone: '0971000000',
        );

        $this->assertTrue($result);
        Queue::assertPushed(SendSmsJob::class);
        $this->assertDatabaseHas('sms_logs', [
            'phone_number' => '0971000000',
            'message_type' => 'activation_otp',
            'status' => 'queued',
            'provider' => 'zamtel',
        ]);
    }

    public function test_queue_template_dispatches_send_sms_job_when_enabled_with_balance(): void
    {
        Queue::fake();

        config(['sms.enabled' => true, 'sms.provider' => 'log']);
        app(\App\Domain\Notifications\Sms\SmsBalanceService::class)->credit(5, 'seed');

        $service = app(OutboundSmsService::class);
        $result = $service->queueTemplate(
            templateKey: 'payment_approved',
            placeholders: ['application_number' => 'ZAQA-TEST-001'],
            phone: '0977000001',
        );

        $this->assertTrue($result);
        Queue::assertPushed(SendSmsJob::class);
        $this->assertDatabaseHas('sms_logs', [
            'message_type' => 'payment_approved',
            'status' => 'queued',
        ]);
    }
}
