<?php

namespace Tests\Feature;

use App\Domain\Fees\QualificationFeeResolver;
use App\Enums\ApplicationStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\Payment;
use App\Models\Qualification;
use App\Models\QualificationCertificate;
use App\Models\QualificationType;
use App\Models\User;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\FeeStructuresSeeder;
use Database\Seeders\QualificationTypesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminCertificatesRegistryTest extends TestCase
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

    /**
     * @return array{Application, Qualification, QualificationCertificate}
     */
    private function createIssuedCertificateFixture(): array
    {
        $type = QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-VER-'.rand(10000, 99999),
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

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'verification_reference_number' => 'ZAQA-Q-REG-1',
            'awarding_institution_name' => 'Test Institution',
            'qualification_holder_name' => 'Jane Holder',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '999999/99/9',
            'title_of_qualification' => 'Diploma in Testing',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'verification_state' => VerificationState::CertificateIssued,
        ]);

        $application->refresh()->load('qualifications');
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

        Storage::fake('local');
        $relativePath = 'qualification-certificates/'.now()->year.'/'.$qualification->id.'/test-token-registry.pdf';
        Storage::disk('local')->put($relativePath, '%PDF-1.4 fake');

        $issuer = User::factory()->activated()->create(['applicant_type' => null]);

        $cert = QualificationCertificate::query()->create([
            'qualification_id' => $qualification->id,
            'application_id' => $application->id,
            'certificate_number' => 'CERT-'.now()->year.'-000099',
            'zaqa_reference_number' => $qualification->verification_reference_number,
            'verification_token' => str_repeat('a', 48),
            'file_path' => $relativePath,
            'issued_by_user_id' => $issuer->id,
            'issued_at' => now(),
            'recipient_email' => $applicant->email,
            'status' => QualificationCertificate::STATUS_ISSUED,
            'metadata' => [],
        ]);

        return [$application, $qualification, $cert];
    }

    public function test_registry_requires_permission(): void
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->givePermissionTo(['dashboard.view']);

        $this->actingAs($user)->get(route('admin.certificates.index'))->assertForbidden();
    }

    public function test_registry_lists_certificates_for_authorized_user(): void
    {
        [, , $cert] = $this->createIssuedCertificateFixture();

        $viewer = User::factory()->activated()->create(['applicant_type' => null]);
        $viewer->givePermissionTo(['dashboard.view', 'admin.certificates.view']);

        $response = $this->actingAs($viewer)->get(route('admin.certificates.index'));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('certificates.data', 1)
            ->where('certificates.data.0.certificate_number', $cert->certificate_number)
            ->where('certificates.data.0.show_url', route('admin.certificates.show', ['qualificationCertificate' => $cert]))
            ->missing('certificates.data.0.download_url')
            ->missing('certificates.data.0.verification_url'));
    }

    public function test_registry_show_page_displays_certificate_detail(): void
    {
        [, , $cert] = $this->createIssuedCertificateFixture();

        $viewer = User::factory()->activated()->create(['applicant_type' => null]);
        $viewer->givePermissionTo(['dashboard.view', 'admin.certificates.view']);

        $this->actingAs($viewer)
            ->get(route('admin.certificates.show', ['qualificationCertificate' => $cert]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Certificates/Show')
                ->where('certificate.certificate_number', $cert->certificate_number)
                ->where('certificate.status', QualificationCertificate::STATUS_ISSUED)
                ->has('preview_document.preview_url')
                ->has('preview_document.download_url'));
    }

    public function test_registry_show_forbidden_without_permission(): void
    {
        [, , $cert] = $this->createIssuedCertificateFixture();

        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->givePermissionTo(['dashboard.view']);

        $this->actingAs($user)
            ->get(route('admin.certificates.show', ['qualificationCertificate' => $cert]))
            ->assertForbidden();
    }

    public function test_registry_preview_streams_inline_pdf(): void
    {
        [, , $cert] = $this->createIssuedCertificateFixture();

        $viewer = User::factory()->activated()->create(['applicant_type' => null]);
        $viewer->givePermissionTo(['dashboard.view', 'admin.certificates.view']);

        $this->actingAs($viewer)
            ->get(route('admin.certificates.preview', ['qualificationCertificate' => $cert]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_registry_download_streams_pdf(): void
    {
        [, , $cert] = $this->createIssuedCertificateFixture();

        $viewer = User::factory()->activated()->create(['applicant_type' => null]);
        $viewer->givePermissionTo(['dashboard.view', 'admin.certificates.view']);

        $this->actingAs($viewer)
            ->get(route('admin.certificates.download', $cert))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_registry_download_forbidden_without_permission(): void
    {
        [, , $cert] = $this->createIssuedCertificateFixture();

        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->givePermissionTo(['dashboard.view']);

        $this->actingAs($user)->get(route('admin.certificates.download', $cert))->assertForbidden();
    }
}
