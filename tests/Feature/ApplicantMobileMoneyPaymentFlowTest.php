<?php

namespace Tests\Feature;

use App\Domain\Applications\ApplicationSubmissionReadinessService;
use App\Domain\Payments\PaymentService;
use App\Enums\ApplicantType;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Jobs\Payments\DispatchMobileMoneyPaymentPromptJob;
use App\Jobs\Payments\QueryCGratePaymentAttemptJob;
use App\Models\Application;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApplicantMobileMoneyPaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        config([
            'cgrate.enabled' => true,
            'queue.default' => 'database',
        ]);

        $this->mock(ApplicationSubmissionReadinessService::class, function ($mock): void {
            $mock->shouldReceive('assertReadyForPayment')->andReturnNull();
        });
    }

    public function test_mobile_money_initiation_dispatches_high_priority_prompt_job(): void
    {
        Queue::fake();

        [$user, $payment] = $this->createMobileMoneyPaymentContext();

        /** @var PaymentService $payments */
        $payments = app(PaymentService::class);
        $result = $payments->initiateOnline($payment, ['mobile_number' => '0973936164'], $user);

        $this->assertNotNull($result['attempt_id'] ?? null);
        $this->assertFalse((bool) ($result['already_pending'] ?? true));

        Queue::assertPushedOn('payments-high', DispatchMobileMoneyPaymentPromptJob::class);
        Queue::assertNotPushed(QueryCGratePaymentAttemptJob::class);
    }

    public function test_attempt_status_endpoint_returns_applicant_safe_fields_only(): void
    {
        Queue::fake();

        [$user, $payment] = $this->createMobileMoneyPaymentContext();
        $attempt = $this->createAttempt($payment, PaymentAttemptStatus::Pending, [
            'response_code' => 106,
            'response_message' => 'Invalid transaction reference',
            'query_attempts' => 6,
        ]);

        $response = $this->actingAs($user)
            ->getJson("/applicant/payments/attempts/{$attempt->id}/status")
            ->assertOk();

        $response->assertJson([
            'status' => 'pending',
            'paid' => false,
        ]);
        $response->assertJsonMissingPath('response_code');
        $response->assertJsonMissingPath('query_attempts');
        $response->assertJsonMissingPath('response_message');
        $response->assertJsonMissingPath('payment_reference');

        Queue::assertPushedOn('payments', QueryCGratePaymentAttemptJob::class);
    }

    public function test_pending_attempt_is_reused_and_reports_already_pending(): void
    {
        Queue::fake();

        [$user, $payment] = $this->createMobileMoneyPaymentContext();
        $this->createAttempt($payment, PaymentAttemptStatus::Pending);
        $payment->forceFill(['status' => PaymentStatus::PendingConfirmation])->save();

        /** @var PaymentService $payments */
        $payments = app(PaymentService::class);
        $result = $payments->initiateOnline($payment, ['mobile_number' => '0973936164'], $user);

        $this->assertTrue((bool) ($result['already_pending'] ?? false));
        $this->assertSame(1, PaymentAttempt::query()->where('payment_id', $payment->id)->count());
        Queue::assertNotPushed(DispatchMobileMoneyPaymentPromptJob::class);
        Queue::assertPushed(QueryCGratePaymentAttemptJob::class);
    }

    public function test_failed_attempt_allows_new_attempt_with_new_reference(): void
    {
        Queue::fake();

        [$user, $payment] = $this->createMobileMoneyPaymentContext();
        $this->createAttempt($payment, PaymentAttemptStatus::Failed, [
            'payment_reference' => 'ZAQA-OLD-REF',
            'failed_at' => now(),
        ]);
        $payment->forceFill(['status' => PaymentStatus::Failed, 'failed_at' => now()])->save();

        /** @var PaymentService $payments */
        $payments = app(PaymentService::class);
        $payments->initiateOnline($payment, ['mobile_number' => '0973936164'], $user);

        $this->assertSame(2, PaymentAttempt::query()->where('payment_id', $payment->id)->count());
        $latest = PaymentAttempt::query()->where('payment_id', $payment->id)->latest('id')->first();
        $this->assertNotSame('ZAQA-OLD-REF', $latest?->payment_reference);
        Queue::assertPushed(DispatchMobileMoneyPaymentPromptJob::class);
    }

    public function test_callback_is_idempotent_and_dispatches_follow_up_poll(): void
    {
        Queue::fake();

        config([
            'payments.cgrate.callback_enabled' => true,
            'payments.cgrate.callback_token' => 'secret-token',
        ]);

        [$user, $payment] = $this->createMobileMoneyPaymentContext();
        $attempt = $this->createAttempt($payment, PaymentAttemptStatus::Pending, [
            'payment_reference' => 'ZAQA-CALLBACK-REF',
        ]);
        $payment->forceFill(['status' => PaymentStatus::PendingConfirmation])->save();

        $this->postJson('/webhooks/cgrate/payment', [
            'token' => 'secret-token',
            'payment_reference' => 'ZAQA-CALLBACK-REF',
        ])->assertOk();

        $this->postJson('/webhooks/cgrate/payment', [
            'token' => 'secret-token',
            'payment_reference' => 'ZAQA-CALLBACK-REF',
        ])->assertOk();

        Queue::assertPushed(QueryCGratePaymentAttemptJob::class, 2);
        $this->assertSame(PaymentAttemptStatus::Pending, $attempt->fresh()->status);
        $this->assertNotSame(PaymentStatus::Confirmed, $payment->fresh()->status);
    }

    public function test_payment_confirmation_is_idempotent(): void
    {
        [$user, $payment] = $this->createMobileMoneyPaymentContext();
        $attempt = $this->createAttempt($payment, PaymentAttemptStatus::Confirmed, [
            'confirmed_at' => now(),
        ]);
        $payment->forceFill(['status' => PaymentStatus::Confirmed, 'confirmed_at' => now()])->save();

        /** @var PaymentService $payments */
        $payments = app(PaymentService::class);

        $first = $payments->applyGatewayVerificationResult($payment, 'confirmed', [
            'provider_transaction_id' => 'TX-1',
            'raw_payload' => ['ok' => true],
        ]);
        $second = $payments->applyGatewayVerificationResult($first, 'confirmed', [
            'provider_transaction_id' => 'TX-1',
            'raw_payload' => ['ok' => true],
        ]);

        $this->assertSame(PaymentStatus::Confirmed, $second->status);
        $this->assertNotNull($attempt->fresh()->confirmed_at);
    }

    /**
     * @return array{0: User, 1: Payment}
     */
    private function createMobileMoneyPaymentContext(): array
    {
        $user = User::factory()->activated()->create([
            'applicant_type' => ApplicantType::Individual,
        ]);

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-MM-'.Str::upper(Str::random(6)),
            'applicant_user_id' => $user->id,
            'applicant_type' => ApplicantType::Individual,
            'service_type' => 'verification',
            'qualification_category' => 'test',
            'current_status' => 'draft',
            'metadata' => [
                'wizard_declarations' => ['terms_accepted_at' => now()->toIso8601String()],
            ],
        ]);

        $invoice = Invoice::query()->create([
            'application_id' => $application->id,
            'invoice_number' => 'INV-'.Str::upper(Str::random(8)),
            'currency' => 'ZMW',
            'amount_cents' => 15000,
            'status' => InvoiceStatus::Issued,
            'issued_at' => now(),
        ]);

        $payment = Payment::query()->create([
            'application_id' => $application->id,
            'invoice_id' => $invoice->id,
            'method' => PaymentMethod::MobileMoney,
            'status' => PaymentStatus::Draft,
            'currency' => 'ZMW',
            'amount_cents' => 15000,
            'provider' => 'cgrate',
            'last_status_at' => now(),
        ]);

        return [$user, $payment];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createAttempt(Payment $payment, PaymentAttemptStatus $status, array $overrides = []): PaymentAttempt
    {
        return PaymentAttempt::query()->create(array_merge([
            'payment_id' => $payment->id,
            'invoice_id' => $payment->invoice_id,
            'application_id' => $payment->application_id,
            'gateway' => 'cgrate',
            'method' => 'mobile_money',
            'payment_reference' => 'ZAQA-'.$payment->invoice_id.'-'.$payment->id.'-'.Str::upper(Str::random(6)),
            'mobile_number' => '0973936164',
            'currency' => 'ZMW',
            'amount_cents' => $payment->amount_cents,
            'status' => $status,
            'initiated_at' => now(),
            'next_query_at' => now(),
            'query_attempts' => 0,
        ], $overrides));
    }
}
