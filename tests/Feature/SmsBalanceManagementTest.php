<?php

namespace Tests\Feature;

use App\Domain\Notifications\OutboundSmsService;
use App\Domain\Notifications\Sms\SmsBalanceService;
use App\Jobs\Notifications\SendSmsJob;
use App\Models\SmsBalanceAccount;
use App\Models\SmsLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SmsBalanceManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config([
            'sms.enabled' => true,
            'sms.provider' => 'log',
        ]);
    }

    public function test_admin_can_add_balance_and_audit_adjustment(): void
    {
        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->assignRole('Super Admin');

        $this->actingAs($admin)
            ->post('/admin/settings/sms/balance', [
                'amount' => 50,
                'reason' => 'Initial top-up',
            ])
            ->assertRedirect(route('admin.settings.sms.balance.index'));

        $this->assertDatabaseHas('sms_balance_adjustments', [
            'adjustment_type' => 'credit',
            'amount' => 50,
            'balance_before' => 0,
            'balance_after' => 50,
            'reason' => 'Initial top-up',
        ]);

        $this->assertSame(50, SmsBalanceAccount::currentReadOnly()->balance);
    }

    public function test_successful_send_debits_balance_once(): void
    {
        app(SmsBalanceService::class)->credit(3, 'seed');

        $service = app(OutboundSmsService::class);
        $service->queueTemplate(
            templateKey: 'payment_approved',
            placeholders: ['application_number' => 'ZAQA-TEST-001'],
            phone: '0977000001',
        );

        $log = SmsLog::query()->firstOrFail();
        (new SendSmsJob($log->id))->handle(
            app(\App\Domain\Notifications\Sms\SmsProviderManager::class),
            app(SmsBalanceService::class),
            app(\App\Domain\Notifications\Sms\SmsBalanceAlertService::class),
        );

        $this->assertSame('sent', $log->fresh()->status);
        $this->assertSame(2, SmsBalanceAccount::currentReadOnly()->balance);
        $this->assertDatabaseHas('sms_balance_adjustments', [
            'adjustment_type' => 'debit',
            'amount' => 1,
            'balance_before' => 3,
            'balance_after' => 2,
        ]);
    }

    public function test_failed_send_does_not_debit_balance(): void
    {
        config(['sms.provider' => 'zamtel', 'sms.zamtel.api_key' => 'key', 'sms.zamtel.sender_id' => 'ZAQA']);

        Http::fake([
            '*' => Http::response(['success' => false], 202),
        ]);

        app(SmsBalanceService::class)->credit(2, 'seed');

        $service = app(OutboundSmsService::class);
        $service->queueTemplate(
            templateKey: 'payment_approved',
            placeholders: ['application_number' => 'ZAQA-TEST-002'],
            phone: '0977000001',
        );

        $log = SmsLog::query()->firstOrFail();

        try {
            (new SendSmsJob($log->id))->handle(
                app(\App\Domain\Notifications\Sms\SmsProviderManager::class),
                app(SmsBalanceService::class),
                app(\App\Domain\Notifications\Sms\SmsBalanceAlertService::class),
            );
        } catch (\Throwable) {
            // no retry in this direct invocation
        }

        $this->assertSame('failed', $log->fresh()->status);
        $this->assertSame(2, SmsBalanceAccount::currentReadOnly()->balance);
    }

    public function test_insufficient_balance_skips_without_dispatching_job(): void
    {
        Queue::fake();

        $service = app(OutboundSmsService::class);
        $result = $service->queueTemplate(
            templateKey: 'payment_approved',
            placeholders: ['application_number' => 'ZAQA-TEST-003'],
            phone: '0977000001',
        );

        $this->assertFalse($result);
        Queue::assertNothingPushed();
        $this->assertDatabaseHas('sms_logs', [
            'status' => 'skipped',
            'skip_reason' => 'insufficient_balance',
        ]);
    }

    public function test_finance_officer_cannot_manage_balance(): void
    {
        $finance = User::factory()->activated()->create(['applicant_type' => null]);
        $finance->assignRole('Finance Officer');

        $this->actingAs($finance)
            ->post('/admin/settings/sms/balance', [
                'amount' => 10,
                'reason' => 'Should fail',
            ])
            ->assertForbidden();
    }
}
