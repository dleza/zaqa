<?php

namespace Tests\Feature;

use App\Domain\Finance\PaymentReceiptPdfService;
use App\Domain\Finance\PaymentReceiptTokenService;
use App\Domain\Payments\InvoiceService;
use App\Enums\ApplicantType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\Payment;
use App\Models\Qualification;
use App\Models\QualificationType;
use App\Models\User;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\FeeStructuresSeeder;
use Database\Seeders\QualificationTypesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReceiptVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BillingCategoriesSeeder::class);
        $this->seed(QualificationTypesSeeder::class);
        $this->seed(FeeStructuresSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_valid_token_shows_receipt_verified_page(): void
    {
        $payment = $this->confirmedPayment();
        $token = app(PaymentReceiptTokenService::class)->ensureToken($payment);

        $this->get(route('receipts.verify', $token))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Receipts/Verify')
                ->where('verification.found', true)
                ->where('verification.status_label', 'Receipt Verified')
                ->where('verification.receipt.receipt_number_display', 'ZQ '.$payment->id)
                ->where('verification.receipt.payment_status', PaymentStatus::Confirmed->value)
            );
    }

    public function test_invalid_token_returns_not_found(): void
    {
        $this->get(route('receipts.verify', 'invalid-token-that-does-not-exist'))
            ->assertNotFound()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Receipts/Verify')
                ->where('verification.found', false)
                ->where('verification.status_label', 'Invalid Receipt')
            );
    }

    public function test_public_page_does_not_expose_gateway_transaction_id_in_props(): void
    {
        $payment = $this->confirmedPayment();
        $token = app(PaymentReceiptTokenService::class)->ensureToken($payment);

        $this->get(route('receipts.verify', $token))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('verification.found', true)
                ->where('verification.receipt.receipt_number_display', 'ZQ '.$payment->id)
            );

        $this->assertStringNotContainsString('TXN-SECRET-12345', $this->get(route('receipts.verify', $token))->getContent());
    }

    public function test_pending_payment_token_is_treated_as_invalid_receipt(): void
    {
        $payment = $this->confirmedPayment();
        $payment->forceFill(['status' => PaymentStatus::PendingConfirmation])->save();
        $token = app(PaymentReceiptTokenService::class)->ensureToken($payment->fresh());

        $this->get(route('receipts.verify', $token))->assertNotFound();
    }

    public function test_receipt_pdf_qr_points_to_public_verification_url(): void
    {
        $payment = $this->confirmedPayment();

        $data = app(PaymentReceiptPdfService::class)->buildViewData($payment->fresh());

        $this->assertStringContainsString('/receipts/', $data['verification_url']);
        $this->assertNotEmpty($data['qr_data_uri']);
        $this->assertNotNull($payment->fresh()->public_receipt_token);
    }

    private function confirmedPayment(): Payment
    {
        $applicant = User::factory()->activated()->create([
            'applicant_type' => ApplicantType::Individual,
            'email' => 'receipt-verify-'.Str::lower((string) Str::ulid()).'@example.test',
        ]);

        ApplicantProfile::query()->create([
            'user_id' => $applicant->id,
            'first_name' => 'Verify',
            'surname' => 'Applicant',
            'email' => $applicant->email,
            'phone_primary' => '0977993537',
        ]);

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-RV-'.random_int(10000, 99999),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => 'pending_payment',
            'is_foreign' => false,
            'metadata' => [],
        ]);

        $type = QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();

        Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test Institution',
            'qualification_holder_name' => 'Verify Applicant',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => "Bachelor's Degree Certificate",
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'certificate_number' => 'CERT-RV-001',
        ]);

        $invoice = app(InvoiceService::class)->ensureInvoice($application->fresh('qualifications'), $applicant);

        return Payment::query()->create([
            'application_id' => $application->id,
            'invoice_id' => $invoice->id,
            'method' => PaymentMethod::MobileMoney,
            'status' => PaymentStatus::Confirmed,
            'currency' => 'ZMW',
            'amount_cents' => (int) $invoice->amount_cents,
            'provider' => 'cgrate',
            'provider_reference' => 'MM-VERIFY',
            'provider_transaction_id' => 'TXN-SECRET-12345',
            'initiated_at' => now()->subMinutes(5),
            'confirmed_at' => now(),
            'last_status_at' => now()->subMinutes(5),
        ])->fresh();
    }
}
