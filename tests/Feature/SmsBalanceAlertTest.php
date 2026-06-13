<?php

namespace Tests\Feature;

use App\Domain\Notifications\Sms\SmsBalanceAlertService;
use App\Domain\Notifications\Sms\SmsBalanceService;
use App\Jobs\Notifications\SendSmsJob;
use App\Mail\Sms\CriticalSmsBalanceAlertMail;
use App\Mail\Sms\LowSmsBalanceAlertMail;
use App\Mail\Sms\ZeroSmsBalanceAlertMail;
use App\Models\SmsBalanceAccount;
use App\Models\SmsLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SmsBalanceAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();

        $this->setAccountThresholds(low: 100, critical: 10);
        config([
            'sms.enabled' => true,
            'sms.provider' => 'log',
            'sms.alert_emails' => ['ict@zaqa.gov.zm', 'finance@zaqa.gov.zm'],
        ]);
    }

    private function setAccountThresholds(int $low, int $critical, ?int $balance = null): void
    {
        SmsBalanceAccount::query()->whereKey(1)->update(array_filter([
            'balance' => $balance,
            'low_balance_threshold' => $low,
            'critical_balance_threshold' => $critical,
            'low_balance_alert_sent_at' => null,
            'critical_balance_alert_sent_at' => null,
            'zero_balance_alert_sent_at' => null,
        ], fn ($value) => $value !== null));
    }

    public function test_low_alert_fires_once_when_crossing_threshold(): void
    {
        $this->setAccountThresholds(low: 100, critical: 10, balance: 101);

        app(SmsBalanceAlertService::class)->evaluateAfterDebit(101, 100);

        Mail::assertQueued(LowSmsBalanceAlertMail::class, 1);
        $this->assertNotNull(SmsBalanceAccount::currentReadOnly()->low_balance_alert_sent_at);
    }

    public function test_low_alert_does_not_repeat_below_threshold(): void
    {
        $this->setAccountThresholds(low: 100, critical: 10, balance: 99);
        SmsBalanceAccount::query()->whereKey(1)->update([
            'low_balance_alert_sent_at' => now(),
        ]);

        app(SmsBalanceAlertService::class)->evaluateAfterDebit(99, 98);

        Mail::assertNothingQueued();
    }

    public function test_critical_alert_fires_once_when_crossing_threshold(): void
    {
        $this->setAccountThresholds(low: 100, critical: 10, balance: 11);

        app(SmsBalanceAlertService::class)->evaluateAfterDebit(11, 10);

        Mail::assertQueued(CriticalSmsBalanceAlertMail::class, 1);
        $this->assertNotNull(SmsBalanceAccount::currentReadOnly()->critical_balance_alert_sent_at);
    }

    public function test_critical_alert_does_not_repeat(): void
    {
        $this->setAccountThresholds(low: 100, critical: 10, balance: 9);
        SmsBalanceAccount::query()->whereKey(1)->update([
            'critical_balance_alert_sent_at' => now(),
        ]);

        app(SmsBalanceAlertService::class)->evaluateAfterDebit(9, 8);

        Mail::assertNothingQueued();
    }

    public function test_zero_alert_fires_once(): void
    {
        $this->setAccountThresholds(low: 100, critical: 10, balance: 1);

        app(SmsBalanceAlertService::class)->evaluateAfterDebit(1, 0);

        Mail::assertQueued(ZeroSmsBalanceAlertMail::class, 1);
        $this->assertNotNull(SmsBalanceAccount::currentReadOnly()->zero_balance_alert_sent_at);
    }

    public function test_zero_alert_does_not_repeat(): void
    {
        $this->setAccountThresholds(low: 100, critical: 10, balance: 0);
        SmsBalanceAccount::query()->whereKey(1)->update([
            'zero_balance_alert_sent_at' => now(),
        ]);

        app(SmsBalanceAlertService::class)->evaluateAfterDebit(0, 0);

        Mail::assertNothingQueued();
    }

    public function test_top_up_resets_alert_state(): void
    {
        SmsBalanceAccount::query()->whereKey(1)->update([
            'balance' => 5,
            'low_balance_alert_sent_at' => now(),
            'critical_balance_alert_sent_at' => now(),
            'zero_balance_alert_sent_at' => now(),
        ]);

        app(SmsBalanceService::class)->credit(195, 'Top-up');

        $account = SmsBalanceAccount::currentReadOnly();
        $this->assertSame(200, $account->balance);
        $this->assertNull($account->low_balance_alert_sent_at);
        $this->assertNull($account->critical_balance_alert_sent_at);
        $this->assertNull($account->zero_balance_alert_sent_at);
    }

    public function test_threshold_crossed_again_after_reset_sends_new_alert(): void
    {
        $this->setAccountThresholds(low: 100, critical: 10, balance: 200);

        app(SmsBalanceAlertService::class)->evaluateAfterDebit(101, 100);
        Mail::assertQueued(LowSmsBalanceAlertMail::class, 1);

        app(SmsBalanceService::class)->credit(100, 'Top-up');
        Mail::assertQueued(LowSmsBalanceAlertMail::class, 1);

        app(SmsBalanceAlertService::class)->evaluateAfterDebit(101, 100);
        Mail::assertQueued(LowSmsBalanceAlertMail::class, 2);
    }

    public function test_multiple_email_recipients_supported(): void
    {
        $this->setAccountThresholds(low: 100, critical: 10, balance: 101);

        app(SmsBalanceAlertService::class)->evaluateAfterDebit(101, 100);

        Mail::assertQueued(LowSmsBalanceAlertMail::class, function (LowSmsBalanceAlertMail $mail) {
            return $mail->hasTo('ict@zaqa.gov.zm') && $mail->hasTo('finance@zaqa.gov.zm');
        });
    }

    public function test_empty_alert_emails_skips_safely(): void
    {
        config(['sms.alert_emails' => []]);
        $this->setAccountThresholds(low: 100, critical: 10, balance: 101);

        app(SmsBalanceAlertService::class)->evaluateAfterDebit(101, 100);

        Mail::assertNothingQueued();
        $this->assertNull(SmsBalanceAccount::currentReadOnly()->low_balance_alert_sent_at);
    }

    public function test_successful_sms_debit_triggers_alert_check(): void
    {
        $this->setAccountThresholds(low: 100, critical: 10, balance: 101);

        SmsLog::query()->create([
            'phone_number' => '0977000001',
            'normalized_phone' => '[260977000001]',
            'message_type' => 'payment_approved',
            'message_body' => 'Test',
            'message_length' => 4,
            'provider' => 'log',
            'status' => 'queued',
        ]);

        $log = SmsLog::query()->firstOrFail();
        (new SendSmsJob($log->id))->handle(
            app(\App\Domain\Notifications\Sms\SmsProviderManager::class),
            app(SmsBalanceService::class),
            app(SmsBalanceAlertService::class),
        );

        Mail::assertQueued(LowSmsBalanceAlertMail::class, 1);
    }
}
