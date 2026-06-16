<?php

namespace Tests\Feature;

use App\Domain\Fees\QualificationFeeResolver;
use App\Domain\Settings\DocumentSignatureService;
use App\Domain\Verification\QualificationDecisionService;
use App\Enums\ApplicationStatus;
use App\Enums\DocumentSignatureType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\Payment;
use App\Models\Qualification;
use App\Models\QualificationCertificate;
use App\Models\QualificationType;
use App\Models\User;
use App\Support\Certificates\CertificateHolderName;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdfWrapper;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\FeeStructuresSeeder;
use Database\Seeders\QualificationTypesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class CertificateHolderNameOutputTest extends TestCase
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

    public function test_verification_certificate_uses_names_as_on_document_and_formats_holder_name(): void
    {
        Storage::fake('local');
        Mail::fake();

        [$application, $qualification, $applicant] = $this->approvedQualification([
            'names_as_on_qualification_document' => 'MARTIN MWALE',
            'qualification_holder_name' => 'Account Holder Name',
        ]);

        $admin = $this->makeLevel2Officer();

        $this->mockPdfLoadView(function (string $view, array $data) {
            $this->assertSame('Martin Mwale', $data['holder_name']);
            $this->assertNotSame('Account Holder Name', $data['holder_name']);
        });

        $this->actingAs($admin)
            ->post(route('admin.verification.qualifications.issue_certificate', $qualification))
            ->assertRedirect();

        $cert = QualificationCertificate::query()->where('qualification_id', $qualification->id)->firstOrFail();
        $this->assertSame('MARTIN MWALE', $cert->metadata['holder_name_raw'] ?? null);
        $this->assertSame('Martin Mwale', $cert->metadata['holder_name_display'] ?? null);
        $this->assertSame(
            CertificateHolderName::SOURCE_NAMES_AS_ON_DOCUMENT,
            $cert->metadata['holder_name_source'] ?? null,
        );

        $this->actingAs($applicant)
            ->get(route('certificates.verify', ['token' => $cert->verification_token]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('verification.certificate.holder_name', 'Martin Mwale'));
    }

    public function test_rejection_certificate_uses_formatted_names_as_on_document(): void
    {
        Storage::fake('local');

        [, $qualification] = $this->rejectedQualification([
            'names_as_on_qualification_document' => 'MARTIN MWALE',
            'qualification_holder_name' => 'Jane Holder',
        ]);

        $level2 = $this->makeLevel2Officer();

        $this->mockPdfLoadView(function (string $view, array $data) {
            $this->assertSame('pdf.rejection-certificate', $view);
            $this->assertSame('Martin Mwale', $data['holder_name']);
        });

        $this->actingAs($level2)
            ->post(route('admin.verification.qualifications.issue_rejection_certificate', $qualification))
            ->assertRedirect();

        $cert = QualificationCertificate::query()->where('qualification_id', $qualification->id)->firstOrFail();
        $this->assertSame('Martin Mwale', $cert->metadata['holder_name_display'] ?? null);
    }

    public function test_holder_name_falls_back_when_names_as_on_document_is_empty(): void
    {
        Storage::fake('local');
        Mail::fake();

        [, $qualification] = $this->approvedQualification([
            'names_as_on_qualification_document' => null,
            'qualification_holder_name' => 'legacy holder',
        ]);

        $this->mockPdfLoadView(function (string $view, array $data) {
            $this->assertSame('Legacy Holder', $data['holder_name']);
        });

        $this->actingAs($this->makeLevel2Officer())
            ->post(route('admin.verification.qualifications.issue_certificate', $qualification))
            ->assertRedirect();

        $cert = QualificationCertificate::query()->where('qualification_id', $qualification->id)->firstOrFail();
        $this->assertSame(
            CertificateHolderName::SOURCE_QUALIFICATION_HOLDER,
            $cert->metadata['holder_name_source'] ?? null,
        );
    }

    public function test_verification_certificate_pdf_includes_signature_when_configured(): void
    {
        Storage::fake('local');
        Mail::fake();

        $admin = $this->makeLevel2Officer();
        app(DocumentSignatureService::class)->storeUpload(
            DocumentSignatureType::Certificate,
            UploadedFile::fake()->createWithContent(
                'signature.png',
                (string) file_get_contents(resource_path('images/zaqa_logo_clean.png')),
            ),
            $admin,
        );

        [, $qualification] = $this->approvedQualification([
            'names_as_on_qualification_document' => 'MARTIN MWALE',
        ]);

        $this->mockPdfLoadView(function (string $view, array $data) {
            $this->assertNotEmpty($data['signature_data_uri'] ?? null);
            $this->assertStringStartsWith('data:image/png;base64,', (string) $data['signature_data_uri']);
        });

        $this->actingAs($admin)
            ->post(route('admin.verification.qualifications.issue_certificate', $qualification))
            ->assertRedirect();
    }

    public function test_certificate_generation_succeeds_when_signature_file_is_missing(): void
    {
        Storage::fake('local');
        Mail::fake();
        Log::spy();

        $admin = $this->makeLevel2Officer();
        $setting = app(DocumentSignatureService::class)->storeUpload(
            DocumentSignatureType::Certificate,
            UploadedFile::fake()->createWithContent(
                'signature.png',
                (string) file_get_contents(resource_path('images/zaqa_logo_clean.png')),
            ),
            $admin,
        );

        Storage::disk('local')->delete($setting->file_path);

        [, $qualification] = $this->approvedQualification([
            'names_as_on_qualification_document' => 'MARTIN MWALE',
        ]);

        $this->mockPdfLoadView(function (string $view, array $data) {
            $this->assertNull($data['signature_data_uri'] ?? null);
        });

        $this->actingAs($admin)
            ->post(route('admin.verification.qualifications.issue_certificate', $qualification))
            ->assertRedirect();

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'Certificate signature file could not be loaded'));
    }

    public function test_rejection_certificate_pdf_includes_signature_when_configured(): void
    {
        Storage::fake('local');

        $level2 = $this->makeLevel2Officer();
        app(DocumentSignatureService::class)->storeUpload(
            DocumentSignatureType::Certificate,
            UploadedFile::fake()->createWithContent(
                'signature.png',
                (string) file_get_contents(resource_path('images/zaqa_logo_clean.png')),
            ),
            $level2,
        );

        [, $qualification] = $this->rejectedQualification([
            'names_as_on_qualification_document' => 'MARTIN MWALE',
        ]);

        $this->mockPdfLoadView(function (string $view, array $data) {
            $this->assertSame('pdf.rejection-certificate', $view);
            $this->assertNotEmpty($data['signature_data_uri'] ?? null);
        });

        $this->actingAs($level2)
            ->post(route('admin.verification.qualifications.issue_rejection_certificate', $qualification))
            ->assertRedirect();
    }

    /**
     * @param  array<string, mixed>  $qualificationOverrides
     * @return array{Application, Qualification, User}
     */
    private function approvedQualification(array $qualificationOverrides = []): array
    {
        $type = QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();
        $applicant = User::factory()->activated()->create([
            'applicant_type' => 'individual',
            'name' => 'Account Applicant Name',
            'email' => 'holder-out-'.Str::lower((string) Str::ulid()).'@example.test',
        ]);

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-VER-'.random_int(10000, 99999),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Approved,
            'verification_state' => VerificationState::ApprovedForCertificate,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
            'approved_at' => now(),
        ]);

        $qualification = Qualification::query()->create(array_merge([
            'application_id' => $application->id,
            'verification_reference_number' => 'ZAQA-Q-'.Str::upper((string) Str::ulid()),
            'awarding_institution_name' => 'Test Institution',
            'qualification_holder_name' => 'Jane Holder',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '999999/99/9',
            'title_of_qualification' => $type->name,
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'verification_state' => VerificationState::ApprovedForCertificate,
        ], $qualificationOverrides));

        $this->createConfirmedPayment($application);

        return [$application, $qualification, $applicant];
    }

    /**
     * @param  array<string, mixed>  $qualificationOverrides
     * @return array{Application, Qualification, User}
     */
    private function rejectedQualification(array $qualificationOverrides = []): array
    {
        $type = QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();
        $applicant = User::factory()->activated()->create([
            'applicant_type' => 'individual',
            'email' => 'rej-holder-out-'.Str::lower((string) Str::ulid()).'@example.test',
        ]);

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-VER-'.random_int(10000, 99999),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::InProgress,
            'verification_state' => VerificationState::UnderLevel2Review,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
        ]);

        $qualification = Qualification::query()->create(array_merge([
            'application_id' => $application->id,
            'verification_reference_number' => 'ZAQA-Q-'.Str::upper((string) Str::ulid()),
            'awarding_institution_name' => 'Test Institution',
            'qualification_holder_name' => 'Jane Holder',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '999999/99/9',
            'title_of_qualification' => $type->name,
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'verification_state' => VerificationState::UnderLevel2Review,
        ], $qualificationOverrides));

        app(QualificationDecisionService::class)->reject(
            $qualification,
            $this->makeLevel2Officer(),
            'Does not meet requirements.',
        );

        $qualification->refresh();
        $this->createConfirmedPayment($application->refresh());

        return [$application, $qualification, $applicant];
    }

    private function createConfirmedPayment(Application $application): void
    {
        $application->loadMissing('qualifications');
        $required = app(QualificationFeeResolver::class)->totalVerificationFeesCents($application);

        Payment::query()->create([
            'application_id' => $application->id,
            'method' => PaymentMethod::Card,
            'status' => PaymentStatus::Confirmed,
            'currency' => 'ZMW',
            'amount_cents' => $required,
            'provider' => 'test',
            'confirmed_at' => now(),
        ]);
    }

    private function makeLevel2Officer(): User
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->assignRole('Verification Officer Level 2');

        return $user;
    }

    private function mockPdfLoadView(callable $assertion, string $output = '%PDF-test-output'): void
    {
        $pdfMock = Mockery::mock(DomPdfWrapper::class);
        $pdfMock->shouldReceive('setPaper')->andReturnSelf();
        $pdfMock->shouldReceive('output')->andReturn($output);

        Pdf::shouldReceive('loadView')
            ->once()
            ->withArgs(function (string $view, array $data) use ($assertion) {
                $assertion($view, $data);

                return true;
            })
            ->andReturn($pdfMock);
    }
}
