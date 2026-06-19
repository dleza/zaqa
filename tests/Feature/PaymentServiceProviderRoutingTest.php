<?php

namespace Tests\Feature;

use App\Domain\Payments\InvoiceService;
use App\Domain\Payments\PaymentService;
use App\Enums\ApplicantType;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Application;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentServiceProviderRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_card_draft_payment_uses_cybersource_provider(): void
    {
        [$user, $application, $invoice] = $this->createPaymentContext();
        $this->mockInvoice($invoice);

        $payment = app(PaymentService::class)->createDraftPayment($application, PaymentMethod::Card, $user);

        $this->assertSame('cybersource', $payment->provider);
        $this->assertSame(PaymentMethod::Card, $payment->method);
        $this->assertSame(PaymentStatus::Draft, $payment->status);
    }

    public function test_existing_card_draft_with_test_provider_is_corrected_to_cybersource(): void
    {
        [$user, $application, $invoice] = $this->createPaymentContext();
        $existing = $this->createDraftPayment($application, $invoice, PaymentMethod::Card, 'test');
        $this->mockInvoice($invoice);

        $payment = app(PaymentService::class)->createDraftPayment($application, PaymentMethod::Card, $user);

        $this->assertTrue($existing->is($payment));
        $this->assertSame('cybersource', $payment->fresh()->provider);
    }

    public function test_mobile_money_draft_payment_still_uses_cgrate_provider(): void
    {
        [$user, $application, $invoice] = $this->createPaymentContext();
        $this->mockInvoice($invoice);

        $payment = app(PaymentService::class)->createDraftPayment($application, PaymentMethod::MobileMoney, $user);

        $this->assertSame('cgrate', $payment->provider);
        $this->assertSame(PaymentMethod::MobileMoney, $payment->method);
        $this->assertSame(PaymentStatus::Draft, $payment->status);
    }

    public function test_existing_mobile_money_draft_provider_correction_still_works(): void
    {
        [$user, $application, $invoice] = $this->createPaymentContext();
        $existing = $this->createDraftPayment($application, $invoice, PaymentMethod::MobileMoney, 'test');
        $this->mockInvoice($invoice);

        $payment = app(PaymentService::class)->createDraftPayment($application, PaymentMethod::MobileMoney, $user);

        $this->assertTrue($existing->is($payment));
        $this->assertSame('cgrate', $payment->fresh()->provider);
    }

    public function test_bank_deposit_and_bank_transfer_new_drafts_still_use_test_provider(): void
    {
        [$user, $application, $invoice] = $this->createPaymentContext();
        $this->mockInvoice($invoice);

        foreach ([PaymentMethod::BankDeposit, PaymentMethod::BankTransfer] as $method) {
            $payment = app(PaymentService::class)->createDraftPayment($application, $method, $user);

            $this->assertSame('test', $payment->provider);
            $this->assertSame($method, $payment->method);
            $this->assertSame(PaymentStatus::Draft, $payment->status);
        }
    }

    public function test_existing_bank_deposit_and_bank_transfer_draft_providers_are_not_corrected(): void
    {
        [$user, $application, $invoice] = $this->createPaymentContext();
        $this->mockInvoice($invoice);

        foreach ([PaymentMethod::BankDeposit, PaymentMethod::BankTransfer] as $method) {
            $existing = $this->createDraftPayment($application, $invoice, $method, 'manual');

            $payment = app(PaymentService::class)->createDraftPayment($application, $method, $user);

            $this->assertTrue($existing->is($payment));
            $this->assertSame('manual', $payment->fresh()->provider);
        }
    }

    private function createPaymentContext(): array
    {
        $user = User::factory()->activated()->create([
            'applicant_type' => ApplicantType::Individual,
        ]);

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-PROVIDER-'.Str::upper(Str::random(8)),
            'applicant_user_id' => $user->id,
            'applicant_type' => ApplicantType::Individual,
            'service_type' => 'verification',
            'qualification_category' => 'test',
            'current_status' => 'draft',
            'is_foreign' => false,
            'metadata' => [
                'wizard_declarations' => [
                    'terms_accepted_at' => now()->toIso8601String(),
                    'information_confirmed_at' => now()->toIso8601String(),
                ],
            ],
        ]);

        $invoice = Invoice::query()->create([
            'application_id' => $application->id,
            'invoice_number' => 'INV-'.Str::upper(Str::random(10)),
            'currency' => 'ZMW',
            'amount_cents' => 15000,
            'status' => InvoiceStatus::Issued,
            'issued_at' => now(),
        ]);

        return [$user, $application, $invoice];
    }

    private function mockInvoice(Invoice $invoice): void
    {
        $this->mock(InvoiceService::class, function ($mock) use ($invoice): void {
            $mock->shouldReceive('ensureInvoice')->andReturn($invoice);
        });
    }

    private function createDraftPayment(
        Application $application,
        Invoice $invoice,
        PaymentMethod $method,
        string $provider,
    ): Payment {
        return Payment::query()->create([
            'application_id' => $application->id,
            'invoice_id' => $invoice->id,
            'method' => $method,
            'status' => PaymentStatus::Draft,
            'currency' => $invoice->currency,
            'amount_cents' => $invoice->amount_cents,
            'provider' => $provider,
            'last_status_at' => now(),
        ]);
    }
}
