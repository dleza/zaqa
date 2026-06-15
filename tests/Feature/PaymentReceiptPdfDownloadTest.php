<?php

namespace Tests\Feature;

use App\Domain\Finance\PaymentReceiptPdfService;
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
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdfWrapper;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\FeeStructuresSeeder;
use Database\Seeders\QualificationTypesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class PaymentReceiptPdfDownloadTest extends TestCase
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

    public function test_applicant_can_download_receipt_for_own_confirmed_payment(): void
    {
        $this->mockPdf();

        [$payment, $applicant] = $this->confirmedPaymentPair();

        $response = $this->actingAs($applicant)->get(route('applicant.payments.receipt.download', $payment));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('receipt-ZQ'.$payment->id.'.pdf', (string) $response->headers->get('content-disposition'));
    }

    public function test_applicant_cannot_download_receipt_for_another_applicants_payment(): void
    {
        [$payment] = $this->confirmedPaymentPair();
        $other = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $this->actingAs($other)->get(route('applicant.payments.receipt.download', $payment))->assertForbidden();
    }

    public function test_applicant_cannot_download_receipt_for_pending_payment(): void
    {
        [$payment, $applicant] = $this->confirmedPaymentPair(status: PaymentStatus::PendingConfirmation);

        $this->actingAs($applicant)->get(route('applicant.payments.receipt.download', $payment))->assertNotFound();
    }

    public function test_finance_user_can_download_receipt_for_confirmed_payment(): void
    {
        $this->mockPdf();

        [$payment] = $this->confirmedPaymentPair();
        $finance = User::factory()->activated()->create(['applicant_type' => null]);
        $finance->givePermissionTo(['dashboard.view', 'finance.payments.view']);

        $this->actingAs($finance)->get(route('admin.finance.payments.receipt.download', $payment))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_unauthorized_user_cannot_download_admin_receipt(): void
    {
        [$payment] = $this->confirmedPaymentPair();
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->givePermissionTo(['dashboard.view']);

        $this->actingAs($user)->get(route('admin.finance.payments.receipt.download', $payment))->assertForbidden();
    }

    public function test_finance_user_cannot_download_receipt_for_unsuccessful_payment(): void
    {
        [$payment] = $this->confirmedPaymentPair(status: PaymentStatus::AwaitingFinanceReview);
        $finance = User::factory()->activated()->create(['applicant_type' => null]);
        $finance->givePermissionTo(['dashboard.view', 'finance.payments.view']);

        $this->actingAs($finance)->get(route('admin.finance.payments.receipt.download', $payment))->assertNotFound();
    }

    public function test_receipt_pdf_service_builds_expected_view_data(): void
    {
        [$payment] = $this->confirmedPaymentPair();

        $data = app(PaymentReceiptPdfService::class)->buildViewData($payment->fresh());

        $this->assertSame('ZQ'.$payment->id, $data['receipt_number']);
        $this->assertSame('ZQ '.$payment->id, $data['receipt_number_display']);
        $this->assertStringContainsString('Kwacha', $data['amount_in_words']);
        $this->assertStringContainsString('Validation & Evaluation', $data['description']);
        $this->assertSame('K '.number_format($payment->amount_cents / 100, 2), $data['breakdown']['total']);
        $this->assertSame(config('zaqa.organization.legal_name'), $data['organization']['legal_name']);
        $this->assertStringContainsString('/receipts/', $data['verification_url']);
        $this->assertNotEmpty($data['qr_data_uri']);
        $this->assertNotNull($payment->fresh()->public_receipt_token);
    }

    public function test_invoice_pdf_download_still_works_after_receipt_feature(): void
    {
        $this->mockPdf();

        [$payment, $applicant] = $this->confirmedPaymentPair();

        $this->actingAs($applicant)->get(route('applicant.invoices.download', $payment->invoice))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    /**
     * @return array{0: Payment, 1: User}
     */
    private function confirmedPaymentPair(PaymentStatus $status = PaymentStatus::Confirmed): array
    {
        $applicant = User::factory()->activated()->create([
            'applicant_type' => ApplicantType::Individual,
            'email' => 'receipt-pdf-'.Str::lower((string) Str::ulid()).'@example.test',
        ]);

        ApplicantProfile::query()->create([
            'user_id' => $applicant->id,
            'first_name' => 'Luka',
            'surname' => 'Chisompola',
            'email' => $applicant->email,
            'phone_primary' => '0977993537',
            'address_line_1' => 'PO BOX 150001 CHAVUMA ROAD',
            'city' => 'ZAMBEZI',
        ]);

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-RCP-'.random_int(10000, 99999),
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
            'qualification_holder_name' => 'Luka Chisompola',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => "Bachelor's Degree Certificate",
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'certificate_number' => 'CERT-RCP-001',
        ]);

        $invoice = app(InvoiceService::class)->ensureInvoice($application->fresh('qualifications'), $applicant);

        $payment = Payment::query()->create([
            'application_id' => $application->id,
            'invoice_id' => $invoice->id,
            'method' => PaymentMethod::MobileMoney,
            'status' => $status,
            'currency' => 'ZMW',
            'amount_cents' => (int) $invoice->amount_cents,
            'provider' => 'cgrate',
            'provider_reference' => 'MM-'.Str::upper(Str::random(8)),
            'provider_transaction_id' => 'TXN-'.Str::upper(Str::random(8)),
            'initiated_at' => now()->subMinutes(5),
            'confirmed_at' => $status === PaymentStatus::Confirmed ? now() : null,
            'last_status_at' => now()->subMinutes(5),
        ]);

        return [$payment->fresh(['application', 'invoice']), $applicant];
    }

    private function mockPdf(): void
    {
        $pdfMock = Mockery::mock(DomPdfWrapper::class);
        $pdfMock->shouldReceive('setPaper')->andReturnSelf();
        $pdfMock->shouldReceive('output')->andReturn('%PDF-receipt-test%');
        Pdf::shouldReceive('loadView')->andReturn($pdfMock);
    }
}
