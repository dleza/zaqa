<?php

namespace Tests\Feature;

use App\Domain\Finance\InvoicePdfService;
use App\Domain\Payments\InvoiceService;
use App\Enums\ApplicantType;
use App\Enums\InvoiceStatus;
use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\Invoice;
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

class InvoicePdfDownloadTest extends TestCase
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

    public function test_applicant_can_download_own_invoice_pdf(): void
    {
        $this->mockPdf();

        [$invoice, $applicant] = $this->issuedInvoicePair();

        $response = $this->actingAs($applicant)->get(route('applicant.invoices.download', $invoice));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('invoice-'.$invoice->invoice_number.'.pdf', (string) $response->headers->get('content-disposition'));
    }

    public function test_applicant_cannot_download_another_applicants_invoice_pdf(): void
    {
        [$invoice] = $this->issuedInvoicePair();
        $other = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $this->actingAs($other)->get(route('applicant.invoices.download', $invoice))->assertForbidden();
    }

    public function test_finance_user_can_download_invoice_pdf(): void
    {
        $this->mockPdf();

        [$invoice] = $this->issuedInvoicePair();
        $finance = User::factory()->activated()->create(['applicant_type' => null]);
        $finance->givePermissionTo(['dashboard.view', 'finance.payments.view']);

        $this->actingAs($finance)->get(route('admin.finance.invoices.download', $invoice))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_unauthorized_user_cannot_download_admin_invoice_pdf(): void
    {
        [$invoice] = $this->issuedInvoicePair();
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->givePermissionTo(['dashboard.view']);

        $this->actingAs($user)->get(route('admin.finance.invoices.download', $invoice))->assertForbidden();
    }

    public function test_invoice_pdf_service_builds_expected_line_items_and_totals(): void
    {
        [$invoice] = $this->issuedInvoicePair(status: InvoiceStatus::Issued);

        $data = app(InvoicePdfService::class)->buildViewData($invoice->fresh());

        $this->assertSame($invoice->invoice_number, $data['invoice_number']);
        $this->assertSame('Pending', $data['status_label']);
        $this->assertCount(1, $data['line_items']);
        $this->assertSame((int) $invoice->amount_cents, $data['total_cents']);
        $this->assertSame((int) $invoice->amount_cents, $data['subtotal_cents']);
        $this->assertStringContainsString('Verification for', $data['line_items'][0]['description']);
    }

    public function test_paid_invoice_pdf_shows_paid_status(): void
    {
        [$invoice] = $this->issuedInvoicePair(status: InvoiceStatus::Paid);
        $invoice->forceFill(['paid_at' => now()])->save();

        $data = app(InvoicePdfService::class)->buildViewData($invoice->fresh());
        $this->assertSame('Paid', $data['status_label']);
    }

    /**
     * @return array{0: Invoice, 1: User}
     */
    private function issuedInvoicePair(InvoiceStatus $status = InvoiceStatus::Issued): array
    {
        $applicant = User::factory()->activated()->create([
            'applicant_type' => ApplicantType::Individual,
            'email' => 'invoice-pdf-'.Str::lower((string) Str::ulid()).'@example.test',
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
            'application_number' => 'ZAQA-INV-'.random_int(10000, 99999),
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
            'certificate_number' => 'CERT-INV-001',
        ]);

        $invoice = app(InvoiceService::class)->ensureInvoice($application->fresh('qualifications'), $applicant);
        $invoice->forceFill(['status' => $status])->save();

        return [$invoice->fresh(), $applicant];
    }

    private function mockPdf(): void
    {
        $pdfMock = Mockery::mock(DomPdfWrapper::class);
        $pdfMock->shouldReceive('setPaper')->andReturnSelf();
        $pdfMock->shouldReceive('output')->andReturn('%PDF-invoice-test%');
        Pdf::shouldReceive('loadView')->andReturn($pdfMock);
    }
}
