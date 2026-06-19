<?php

namespace Tests\Feature;

use App\Domain\Finance\InvoiceDocumentPresenter;
use App\Domain\Finance\InvoicePdfService;
use App\Domain\Finance\PaymentProofReviewService;
use App\Domain\Finance\PaymentReceiptPdfService;
use App\Domain\Finance\QuotationExpiryService;
use App\Domain\Payments\InvoiceService;
use App\Domain\Payments\PaymentService;
use App\Domain\Reports\PaymentsRevenueReportService;
use App\Enums\ApplicationStatus;
use App\Enums\DocumentType;
use App\Enums\InvoiceDocumentType;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Qualification;
use App\Models\QualificationDocument;
use App\Models\QualificationType;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdfWrapper;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\FeeStructuresSeeder;
use Database\Seeders\QualificationTypesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class QuotationInvoiceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(BillingCategoriesSeeder::class);
        $this->seed(QualificationTypesSeeder::class);
        $this->seed(FeeStructuresSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_payment_step_creates_quotation_not_invoice(): void
    {
        [$application, $applicant] = $this->makePaymentReadyApplication();

        $this->actingAs($applicant)
            ->get(route('applicant.applications.edit', ['application' => $application->id, 'step' => 'payment']))
            ->assertOk();

        $quotation = Invoice::query()->where('application_id', $application->id)->firstOrFail();
        $this->assertSame(InvoiceDocumentType::Quotation, $quotation->document_type);
        $this->assertSame(InvoiceStatus::Issued, $quotation->status);
        $this->assertNotNull($quotation->quotation_number);
        $this->assertStringStartsWith('QUO-', (string) $quotation->quotation_number);
        $this->assertNotNull($quotation->expires_at);
        $this->assertTrue($quotation->expires_at->greaterThan(now()->addDays(59)));
    }

    public function test_quotation_pdf_heading_and_applicant_download_label(): void
    {
        [$application, $applicant] = $this->makePaymentReadyApplication();
        $quotation = app(InvoiceService::class)->ensureInvoice($application->fresh('qualifications'), $applicant);

        $data = app(InvoicePdfService::class)->buildViewData($quotation);
        $this->assertSame('Quotation', $data['document_title']);
        $this->assertSame($quotation->quotation_number, $data['document_number']);

        $presenter = app(InvoiceDocumentPresenter::class);
        $this->assertSame('Download quotation', $presenter->downloadButtonLabel($quotation));

        $this->mockPdf();
        $this->actingAs($applicant)
            ->get(route('applicant.invoices.download', $quotation))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_applicant_payment_step_shows_download_quotation_and_expiry(): void
    {
        [$application, $applicant] = $this->makePaymentReadyApplication();

        $this->actingAs($applicant)
            ->get(route('applicant.applications.edit', ['application' => $application->id, 'step' => 'payment']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Applicant/Applications/Edit', false)
                ->where('application.invoice.document_type', 'quotation')
                ->where('application.invoice.download_label', 'Download quotation')
                ->has('application.invoice.expires_at')
            );
    }

    public function test_applicant_invoices_index_distinguishes_quotations_and_invoices(): void
    {
        [$application, $applicant, $payment, $finance] = $this->makeManualProofPaymentReady();

        $this->actingAs($applicant)
            ->get(route('applicant.invoices'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Applicant/Invoices', false)
                ->has('invoices', 1)
                ->where('invoices.0.document_type', 'quotation')
                ->where('invoices.0.document_title', 'Quotation')
                ->where('invoices.0.download_label', 'Download quotation')
            );

        app(\App\Domain\Finance\PaymentProofReviewService::class)->approve($payment, $finance, 'Approved');

        $this->actingAs($applicant)
            ->get(route('applicant.invoices'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Applicant/Invoices', false)
                ->has('invoices', 1)
                ->where('invoices.0.document_type', 'invoice')
                ->where('invoices.0.document_title', 'Invoice')
                ->where('invoices.0.download_label', 'Download invoice')
                ->has('invoices.0.quotation_number')
                ->has('invoices.0.converted_to_invoice_at')
            );
    }

    public function test_finance_reports_exclude_unpaid_quotations(): void
    {
        [$application, $applicant] = $this->makePaymentReadyApplication();
        $quotation = app(InvoiceService::class)->ensureInvoice($application->fresh('qualifications'), $applicant);

        $from = now()->subDay();
        $to = now()->addDay();
        $service = app(PaymentsRevenueReportService::class);
        $summary = $service->dashboard($from, $to, null)['summary'];

        $this->assertSame(0, $summary['paid_invoices']);
        $this->assertSame(0, $summary['total_paid_amount_cents']);

        $quotation->forceFill([
            'document_type' => InvoiceDocumentType::Invoice,
            'status' => InvoiceStatus::Paid,
            'paid_at' => now(),
            'converted_to_invoice_at' => now(),
            'invoice_number' => 'INV-TEST-PAID-1',
        ])->save();

        $summaryAfter = $service->dashboard($from, $to, null)['summary'];
        $this->assertSame(1, $summaryAfter['paid_invoices']);
        $this->assertSame((int) $quotation->amount_cents, $summaryAfter['total_paid_amount_cents']);
    }

    public function test_successful_payment_converts_quotation_to_invoice_and_receipt_remains_available(): void
    {
        [$application, $applicant, $payment, $finance] = $this->makeManualProofPaymentReady();
        $quotation = Invoice::query()->where('application_id', $application->id)->firstOrFail();
        $quotationNumber = $quotation->quotation_number;

        app(PaymentProofReviewService::class)->approve($payment, $finance, 'Approved in test');

        $invoice = $quotation->fresh();
        $this->assertSame(InvoiceDocumentType::Invoice, $invoice->document_type);
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame($quotationNumber, $invoice->quotation_number);
        $this->assertStringStartsWith('INV-', (string) $invoice->invoice_number);
        $this->assertNotNull($invoice->converted_to_invoice_at);
        $this->assertNotNull($invoice->paid_at);

        $confirmed = Payment::query()->where('application_id', $application->id)->where('status', PaymentStatus::Confirmed)->first();
        $this->assertNotNull($confirmed);
        $this->assertTrue(app(\App\Domain\Finance\PaymentReceiptPdfService::class)->isEligible($confirmed));

        $data = app(InvoicePdfService::class)->buildViewData($invoice);
        $this->assertSame('Invoice', $data['document_title']);
        $this->assertSame('Download invoice', app(InvoiceDocumentPresenter::class)->downloadButtonLabel($invoice));
    }

    public function test_existing_payment_flow_still_auto_submits_after_payment(): void
    {
        [$application, , $payment, $finance] = $this->makeManualProofPaymentReady();

        app(PaymentProofReviewService::class)->approve($payment, $finance, 'Approved');

        $application->refresh();
        $this->assertSame(ApplicationStatus::Submitted, $application->current_status);
        $this->assertNotNull($application->submitted_at);
        $this->assertNotNull($application->paid_at);
    }

    public function test_quotation_expires_after_sixty_days_and_cleans_up_application(): void
    {
        [$application, $applicant] = $this->makePaymentReadyApplication();
        $quotation = app(InvoiceService::class)->ensureInvoice($application->fresh('qualifications'), $applicant);
        $quotation->forceFill(['expires_at' => now()->subMinute()])->save();

        $result = app(QuotationExpiryService::class)->expireDueQuotations();
        $this->assertSame(1, $result['expired']);

        $application->refresh();
        $quotation->refresh();
        $this->assertSame(ApplicationStatus::ExpiredUnpaid, $application->current_status);
        $this->assertSame(InvoiceStatus::Expired, $quotation->status);

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'quotation.expired_application_deleted',
        ]);

        $remaining = QualificationDocument::query()
            ->where('application_id', $application->id)
            ->whereNull('deleted_at')
            ->count();
        $this->assertSame(0, $remaining);
    }

    public function test_paid_applications_are_never_expired(): void
    {
        [$application, $applicant, $payment, $finance] = $this->makeManualProofPaymentReady();
        $quotation = Invoice::query()->where('application_id', $application->id)->firstOrFail();
        $quotation->forceFill(['expires_at' => now()->subMinute()])->save();

        app(PaymentProofReviewService::class)->approve($payment, $finance, 'Approved');

        $result = app(QuotationExpiryService::class)->expireDueQuotations();
        $this->assertSame(0, $result['expired']);
        $this->assertNotSame(ApplicationStatus::ExpiredUnpaid, $application->fresh()->current_status);
    }

    public function test_submitted_applications_are_never_expired(): void
    {
        [$application, $applicant] = $this->makePaymentReadyApplication();
        $quotation = app(InvoiceService::class)->ensureInvoice($application->fresh('qualifications'), $applicant);
        $quotation->forceFill(['expires_at' => now()->subMinute()])->save();
        $application->forceFill([
            'current_status' => ApplicationStatus::Submitted,
            'submitted_at' => now(),
        ])->save();

        $result = app(QuotationExpiryService::class)->expireDueQuotations();
        $this->assertSame(0, $result['expired']);
    }

    public function test_expired_application_cannot_be_paid(): void
    {
        [$application, $applicant] = $this->makePaymentReadyApplication();
        $application->forceFill(['current_status' => ApplicationStatus::ExpiredUnpaid])->save();

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(PaymentService::class)->createDraftPayment($application->fresh(), PaymentMethod::Card, $applicant);
    }

    public function test_expire_command_runs_successfully(): void
    {
        $this->artisan('quotations:expire')->assertSuccessful();
    }

    /**
     * @return array{0: Application, 1: User, 2: Payment, 3: User}
     */
    private function makeManualProofPaymentReady(): array
    {
        [$application, $applicant] = $this->makePaymentReadyApplication();
        $invoice = app(InvoiceService::class)->ensureInvoice($application->fresh('qualifications'), $applicant);

        $proof = QualificationDocument::query()->create([
            'application_id' => $application->id,
            'qualification_id' => null,
            'document_type' => DocumentType::PaymentProof->value,
            'original_name' => 'proof.pdf',
            'stored_name' => 'proof.pdf',
            'disk' => 'local',
            'path' => 'private/applications/'.$application->uuid.'/payment_proof/proof.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 100,
            'sha256_hash' => hash('sha256', 'proof'),
            'visibility' => 'private',
            'uploaded_by_user_id' => $applicant->id,
            'version_number' => 1,
            'is_current_version' => true,
        ]);

        $payment = Payment::query()->create([
            'application_id' => $application->id,
            'invoice_id' => $invoice->id,
            'method' => PaymentMethod::BankDeposit,
            'status' => PaymentStatus::AwaitingFinanceReview,
            'currency' => $invoice->currency,
            'amount_cents' => $invoice->amount_cents,
            'provider' => 'manual',
            'proof_document_id' => $proof->id,
            'awaiting_finance_review_at' => now(),
            'last_status_at' => now(),
        ]);

        $finance = User::factory()->activated()->create(['applicant_type' => null]);
        $finance->assignRole('Finance Officer');

        return [$application, $applicant, $payment, $finance];
    }

    /**
     * @return array{0: Application, 1: User}
     */
    private function makePaymentReadyApplication(): array
    {
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual', 'email' => 'applicant-'.Str::lower((string) Str::ulid()).'@example.test']);
        $qualificationType = QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();

        ApplicantProfile::create([
            'user_id' => $applicant->id,
            'first_name' => 'Applicant',
            'surname' => 'Test',
            'nrc_number' => '111111/11/1',
            'email' => $applicant->email,
            'phone_primary' => $applicant->phone_primary,
        ]);

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-QUO-'.random_int(1000, 9999),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => 'draft',
            'is_foreign' => false,
            'metadata' => [
                'submitting_for' => 'self',
                'wizard_declarations' => [
                    'terms_accepted_at' => now()->toIso8601String(),
                    'information_confirmed_at' => now()->toIso8601String(),
                ],
            ],
        ]);

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test Institute',
            'qualification_holder_name' => 'Applicant Test',
            'nrc_passport_number' => '111111/11/1',
            'certificate_number' => 'CERT-QUO-001',
            'title_of_qualification' => 'Diploma in Testing',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'diploma',
            'qualification_type_id' => $qualificationType->id,
            'is_foreign_qualification' => false,
        ]);

        $path = 'private/applications/'.$application->uuid.'/certificate_copy/certificate.pdf';
        Storage::disk('local')->put($path, 'certificate-content');

        QualificationDocument::query()->create([
            'application_id' => $application->id,
            'qualification_id' => $qualification->id,
            'document_type' => DocumentType::CertificateCopy->value,
            'original_name' => 'certificate.pdf',
            'stored_name' => 'certificate.pdf',
            'disk' => 'local',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 100,
            'sha256_hash' => hash('sha256', 'certificate-content'),
            'visibility' => 'private',
            'uploaded_by_user_id' => $applicant->id,
            'version_number' => 1,
            'is_current_version' => true,
        ]);

        $nrcPath = 'private/applications/'.$application->uuid.'/nrc_copy/nrc.pdf';
        Storage::disk('local')->put($nrcPath, 'nrc-content');
        QualificationDocument::query()->create([
            'application_id' => $application->id,
            'qualification_id' => null,
            'document_type' => DocumentType::NrcCopy->value,
            'original_name' => 'nrc.pdf',
            'stored_name' => 'nrc.pdf',
            'disk' => 'local',
            'path' => $nrcPath,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 100,
            'sha256_hash' => hash('sha256', 'nrc-content'),
            'visibility' => 'private',
            'uploaded_by_user_id' => $applicant->id,
            'version_number' => 1,
            'is_current_version' => true,
        ]);

        return [$application, $applicant];
    }

    private function mockPdf(): void
    {
        $pdfMock = Mockery::mock(DomPdfWrapper::class);
        $pdfMock->shouldReceive('setPaper')->andReturnSelf();
        $pdfMock->shouldReceive('output')->andReturn('%PDF-quotation-test%');
        Pdf::shouldReceive('loadView')->andReturn($pdfMock);
    }
}
