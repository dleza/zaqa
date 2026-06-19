<?php

namespace Tests\Feature;

use App\Domain\Applications\ApplicationAutoSubmissionService;
use App\Domain\Payments\Gateways\CyberSource\CyberSourceCaptureContextService;
use App\Domain\Payments\Gateways\CyberSource\CyberSourceClientFactory;
use App\Domain\Payments\Gateways\CyberSource\CyberSourcePaymentService;
use App\Domain\Payments\Gateways\CyberSource\CyberSourcePayloadSanitizer;
use App\Domain\Payments\Gateways\CyberSource\CyberSourceStatusMapper;
use App\Domain\Payments\InvoiceService;
use App\Enums\ApplicantType;
use App\Enums\InvoiceDocumentType;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Application;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use CyberSource\Api\MicroformIntegrationApi;
use CyberSource\Api\PaymentsApi;
use CyberSource\Model\GenerateCaptureContextRequest;
use CyberSource\Model\PtsV2PaymentsPost201Response;
use CyberSource\Model\PtsV2PaymentsPost201ResponseProcessorInformation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CyberSourceCardPaymentEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_capture_context_creation_creates_card_draft_and_returns_microform_context(): void
    {
        [$user, $application, $invoice] = $this->createPaymentContext();
        $this->mockInvoice($invoice);
        $this->enableCyberSourceConfig();
        $this->bindCaptureContextApi('capture-context-jwt');

        $response = $this->actingAs($user)
            ->postJson("/applicant/applications/{$application->id}/payment/card/capture-context")
            ->assertOk();

        $payment = Payment::query()->where('application_id', $application->id)->firstOrFail();

        $response->assertJson([
            'payment_id' => $payment->id,
            'provider_reference' => $payment->provider_reference,
            'capture_context' => 'capture-context-jwt',
            'card_networks' => ['VISA', 'MASTERCARD'],
        ]);

        $this->assertSame(PaymentMethod::Card, $payment->method);
        $this->assertSame(PaymentStatus::Draft, $payment->status);
        $this->assertSame('cybersource', $payment->provider);
        $this->assertNotEmpty($payment->provider_reference);
    }

    public function test_card_payment_endpoints_enforce_applicant_ownership(): void
    {
        [$owner, $application, $invoice] = $this->createPaymentContext();
        $otherUser = User::factory()->activated()->create([
            'applicant_type' => ApplicantType::Individual,
        ]);

        $payment = $this->createCardPayment($application, $invoice);

        $this->actingAs($otherUser)
            ->postJson("/applicant/applications/{$application->id}/payment/card/capture-context")
            ->assertForbidden();

        $this->actingAs($otherUser)
            ->postJson("/applicant/payments/{$payment->id}/card/confirm", [
                'transient_token_jwt' => 'header.payload.signature',
            ])
            ->assertForbidden();

        $this->actingAs($owner)
            ->postJson("/applicant/payments/{$payment->id}/card/confirm", [])
            ->assertJsonValidationErrors('transient_token_jwt');
    }

    public function test_successful_token_payment_confirms_payment_through_payment_service(): void
    {
        [$user, $application, $invoice] = $this->createPaymentContext();
        $payment = $this->createCardPayment($application, $invoice);

        $this->enableCyberSourceConfig();
        $this->bindPaymentApi(new PtsV2PaymentsPost201Response([
            'id' => 'cybs-payment-id',
            'status' => 'AUTHORIZED',
            'reconciliationId' => 'recon-123',
            'processorInformation' => new PtsV2PaymentsPost201ResponseProcessorInformation([
                'transactionId' => 'processor-tx-123',
                'approvalCode' => 'AUTH123',
                'responseCode' => '100',
            ]),
        ]));
        $this->mockAutoSubmission();

        $this->actingAs($user)
            ->postJson("/applicant/payments/{$payment->id}/card/confirm", [
                'transient_token_jwt' => 'header.payload.signature',
            ])
            ->assertOk()
            ->assertJson([
                'payment_status' => 'confirmed',
                'redirect_url' => route('applicant.applications.feedback.show', $application),
                'message' => 'Payment confirmed successfully. Your application has been submitted to ZAQA for verification.',
            ]);

        $payment->refresh();
        $invoice->refresh();

        $this->assertSame(PaymentStatus::Confirmed, $payment->status);
        $this->assertSame('cybs-payment-id', $payment->provider_transaction_id);
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertNotNull($invoice->paid_at);
        $this->assertStringNotContainsString('header.payload.signature', json_encode($payment->raw_payload));
    }

    public function test_failed_token_payment_does_not_mark_invoice_paid(): void
    {
        [$user, $application, $invoice] = $this->createPaymentContext();
        $payment = $this->createCardPayment($application, $invoice);

        $this->enableCyberSourceConfig();
        $this->bindPaymentApi(new PtsV2PaymentsPost201Response([
            'id' => 'cybs-failed-payment-id',
            'status' => 'INVALID_REQUEST',
            'message' => 'Invalid transient token.',
        ]));

        $this->actingAs($user)
            ->postJson("/applicant/payments/{$payment->id}/card/confirm", [
                'transient_token_jwt' => 'header.payload.signature',
            ])
            ->assertOk()
            ->assertJson([
                'payment_status' => 'failed',
                'redirect_url' => route('applicant.applications.edit', [
                    'application' => $application->id,
                    'step' => 'payment',
                ]),
                'message' => 'Payment failed. Please try again or choose another payment method.',
            ]);

        $payment->refresh();
        $invoice->refresh();

        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertSame(InvoiceStatus::Issued, $invoice->status);
        $this->assertNull($invoice->paid_at);
        $this->assertStringNotContainsString('header.payload.signature', json_encode($payment->raw_payload));
    }

    public function test_confirm_endpoint_rejects_duplicate_confirmed_payment_before_gateway_charge(): void
    {
        [$user, $application, $invoice] = $this->createPaymentContext([
            'status' => InvoiceStatus::Paid,
            'paid_at' => now(),
        ]);
        $payment = $this->createCardPayment($application, $invoice, [
            'status' => PaymentStatus::Confirmed,
            'confirmed_at' => now(),
            'provider_transaction_id' => 'existing-cybs-id',
        ]);

        $api = $this->createMock(PaymentsApi::class);
        $api->expects($this->never())->method('createPayment');
        $this->enableCyberSourceConfig();
        $this->bindPaymentApi(null, $api);

        $this->actingAs($user)
            ->postJson("/applicant/payments/{$payment->id}/card/confirm", [
                'transient_token_jwt' => 'header.payload.signature',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment');

        $this->assertSame('existing-cybs-id', $payment->fresh()->provider_transaction_id);
    }

    /**
     * @param  array<string, mixed>  $invoiceOverrides
     * @return array{0: User, 1: Application, 2: Invoice}
     */
    private function createPaymentContext(array $invoiceOverrides = []): array
    {
        $user = User::factory()->activated()->create([
            'applicant_type' => ApplicantType::Individual,
        ]);

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-CYBS-'.Str::upper(Str::random(8)),
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

        $invoice = Invoice::query()->create(array_merge([
            'application_id' => $application->id,
            'invoice_number' => 'INV-'.Str::upper(Str::random(10)),
            'document_type' => InvoiceDocumentType::Invoice,
            'currency' => 'ZMW',
            'amount_cents' => 15000,
            'status' => InvoiceStatus::Issued,
            'issued_at' => now(),
        ], $invoiceOverrides));

        return [$user, $application, $invoice];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createCardPayment(Application $application, Invoice $invoice, array $overrides = []): Payment
    {
        return Payment::query()->create(array_merge([
            'application_id' => $application->id,
            'invoice_id' => $invoice->id,
            'method' => PaymentMethod::Card,
            'status' => PaymentStatus::Draft,
            'currency' => $invoice->currency,
            'amount_cents' => $invoice->amount_cents,
            'provider' => 'cybersource',
            'provider_reference' => 'CYBS-'.$invoice->id.'-'.Str::upper(Str::random(8)),
            'last_status_at' => now(),
        ], $overrides));
    }

    private function mockInvoice(Invoice $invoice): void
    {
        $this->mock(InvoiceService::class, function ($mock) use ($invoice): void {
            $mock->shouldReceive('ensureInvoice')->andReturn($invoice);
        });
    }

    private function mockAutoSubmission(): void
    {
        $this->mock(ApplicationAutoSubmissionService::class, function ($mock): void {
            $mock->shouldReceive('submitAfterPaymentSatisfied')
                ->once()
                ->andReturnUsing(fn (Application $application) => $application);
        });
    }

    private function enableCyberSourceConfig(): void
    {
        config([
            'cybersource.enabled' => true,
            'cybersource.run_environment' => 'apitest.cybersource.com',
            'cybersource.merchant_id' => 'test_merchant',
            'cybersource.key_id' => 'test_key_id',
            'cybersource.secret_key' => 'test_secret',
            'cybersource.auth_type' => 'JWT',
            'cybersource.jwt_key_type' => 'SHARED_SECRET',
            'cybersource.target_origins' => ['https://example.test'],
            'cybersource.allowed_card_networks' => ['VISA', 'MASTERCARD'],
            'cybersource.allowed_payment_types' => ['CARD'],
            'cybersource.capture' => true,
        ]);
    }

    private function bindCaptureContextApi(string $captureContext): void
    {
        $api = $this->createMock(MicroformIntegrationApi::class);
        $api->expects($this->once())
            ->method('generateCaptureContext')
            ->with($this->callback(fn (GenerateCaptureContextRequest $request): bool => $request->getTargetOrigins() === ['https://example.test']))
            ->willReturn([
                $captureContext,
                201,
                ['v-c-request-id' => ['capture-request-123']],
            ]);

        $this->app->instance(
            CyberSourceCaptureContextService::class,
            new CyberSourceCaptureContextService(app(CyberSourceClientFactory::class), $api),
        );
    }

    private function bindPaymentApi(?PtsV2PaymentsPost201Response $response = null, ?PaymentsApi $api = null): void
    {
        $api ??= $this->createMock(PaymentsApi::class);

        if ($response) {
            $api->expects($this->once())
                ->method('createPayment')
                ->willReturn([
                    $response,
                    201,
                    ['v-c-request-id' => ['payment-request-123']],
                ]);
        }

        $this->app->instance(
            CyberSourcePaymentService::class,
            new CyberSourcePaymentService(
                app(CyberSourceClientFactory::class),
                new CyberSourceStatusMapper(),
                new CyberSourcePayloadSanitizer(),
                $api,
            ),
        );
    }
}
