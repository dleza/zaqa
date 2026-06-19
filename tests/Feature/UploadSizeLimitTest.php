<?php

namespace Tests\Feature;

use App\Domain\Verification\AssignmentService;
use App\Domain\Verification\QualificationLevel1ReviewService;
use App\Enums\ApplicantType;
use App\Enums\DocumentType;
use App\Enums\VerificationState;
use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\Qualification;
use App\Models\QualificationDocument;
use App\Models\QualificationType;
use App\Models\User;
use App\Support\Uploads\UserUploadLimit;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\FeeStructuresSeeder;
use Database\Seeders\QualificationTypesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class UploadSizeLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Config::set('zaqa.uploads.max_file_size_mb', 3);
        Config::set('documents.max_upload_kb', 3 * 1024);

        $this->seed([
            RolesAndPermissionsSeeder::class,
            BillingCategoriesSeeder::class,
            QualificationTypesSeeder::class,
            FeeStructuresSeeder::class,
            CountriesSeeder::class,
        ]);
    }

    public function test_file_under_configured_limit_uploads_successfully(): void
    {
        [$user, $application, $qualification] = $this->makeApplicantQualification();

        $this->actingAs($user)
            ->post("/applicant/applications/{$application->id}/documents", [
                'document_type' => DocumentType::CertificateCopy->value,
                'qualification_id' => $qualification->id,
                'file' => UploadedFile::fake()->create('certificate.pdf', 500, 'application/pdf'),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('qualification_documents', [
            'application_id' => $application->id,
            'qualification_id' => $qualification->id,
            'document_type' => DocumentType::CertificateCopy->value,
        ]);
    }

    public function test_file_over_configured_limit_is_rejected_with_friendly_message(): void
    {
        [$user, $application, $qualification] = $this->makeApplicantQualification();
        $maxKb = UserUploadLimit::maxFileSizeKb();

        $response = $this->actingAs($user)
            ->post("/applicant/applications/{$application->id}/documents", [
                'document_type' => DocumentType::CertificateCopy->value,
                'qualification_id' => $qualification->id,
                'file' => UploadedFile::fake()->create('certificate.pdf', $maxKb + 1, 'application/pdf'),
            ]);

        $response->assertSessionHasErrors(['file']);
        $this->assertSame(
            UserUploadLimit::fileTooLargeMessage(),
            (string) session('errors')->get('file')[0],
        );
    }

    public function test_changing_config_to_10_mb_allows_larger_files(): void
    {
        Config::set('zaqa.uploads.max_file_size_mb', 10);
        Config::set('documents.max_upload_kb', 10 * 1024);

        [$user, $application, $qualification] = $this->makeApplicantQualification();

        $this->actingAs($user)
            ->post("/applicant/applications/{$application->id}/documents", [
                'document_type' => DocumentType::CertificateCopy->value,
                'qualification_id' => $qualification->id,
                'file' => UploadedFile::fake()->create('certificate.pdf', 5000, 'application/pdf'),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('qualification_documents', [
            'application_id' => $application->id,
            'qualification_id' => $qualification->id,
            'document_type' => DocumentType::CertificateCopy->value,
        ]);
    }

    public function test_applicant_upload_validation_uses_config(): void
    {
        $this->assertSame(3072, UserUploadLimit::maxFileSizeKb());
        $this->assertSame(
            'The uploaded file is too large. Maximum allowed size is 3 MB.',
            UserUploadLimit::fileTooLargeMessage(),
        );
    }

    public function test_level1_attachment_validation_uses_config(): void
    {
        [$qualification, $level1, $type] = $this->makeLevel2ReadyQualification();
        $maxKb = UserUploadLimit::maxFileSizeKb();

        $this->actingAs($level1)
            ->post(route('admin.verification.qualifications.level1_complete', ['qualification' => $qualification->id]), [
                'qualification_type_id' => $type->id,
                'recommended_for_award' => '0',
                'findings' => 'Checks complete.',
                'attachment' => UploadedFile::fake()->create('notes.pdf', $maxKb + 1, 'application/pdf'),
            ])
            ->assertSessionHasErrors(['attachment']);
    }

    public function test_level2_send_back_attachment_validation_uses_config(): void
    {
        [$qualification, , , $level2] = $this->makeLevel2ReadyQualification(withLevel2Owner: true);
        $maxKb = UserUploadLimit::maxFileSizeKb();

        $this->actingAs($level2)
            ->post(route('admin.verification.qualifications.send_back_to_level1', $qualification), [
                'comment' => 'Please correct the findings.',
                'attachment' => UploadedFile::fake()->create('notes.pdf', $maxKb + 1, 'application/pdf'),
            ])
            ->assertSessionHasErrors(['attachment']);
    }

    public function test_existing_valid_uploads_still_work(): void
    {
        [$user, $application, $qualification] = $this->makeApplicantQualification();

        QualificationDocument::query()->create([
            'application_id' => $application->id,
            'qualification_id' => $qualification->id,
            'document_type' => DocumentType::CertificateCopy->value,
            'original_name' => 'certificate.pdf',
            'stored_name' => 'certificate.pdf',
            'disk' => 'local',
            'path' => 'applications/'.$application->id.'/certificate.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 120 * 1024,
            'sha256_hash' => hash('sha256', 'certificate'),
            'visibility' => 'private',
            'uploaded_by_user_id' => $user->id,
            'version_number' => 1,
            'is_current_version' => true,
        ]);

        $this->actingAs($user)
            ->get(route('applicant.applications.edit', ['application' => $application, 'step' => 'qualification']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('application.qualifications.0.has_certificate_document', true)
            );
    }

    public function test_inertia_shared_props_include_upload_limits(): void
    {
        $user = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $this->actingAs($user)
            ->get(route('applicant.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('uploads.max_file_size_mb', 3)
                ->where('uploads.max_file_size_label', 'Maximum file size: 3 MB')
                ->where('uploads.pdf_or_image_hint', 'PDF or image files only (JPG, PNG, WEBP) — max 3 MB')
            );
    }

    /**
     * @return array{0: User, 1: Application, 2: Qualification}
     */
    private function makeApplicantQualification(): array
    {
        $user = User::factory()->activated()->create([
            'applicant_type' => ApplicantType::Individual,
        ]);

        ApplicantProfile::query()->create([
            'user_id' => $user->id,
            'first_name' => 'John',
            'surname' => 'Doe',
            'gender' => 'male',
            'nrc_number' => '111111/11/1',
            'identity_type' => 'nrc',
            'email' => $user->email,
            'phone_primary' => $user->phone_primary,
            'identity_document_uploaded_at' => now(),
        ]);

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-UPL-'.random_int(1000, 9999),
            'applicant_user_id' => $user->id,
            'applicant_type' => ApplicantType::Individual,
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => 'draft',
            'is_foreign' => false,
            'metadata' => ['submitting_for' => 'self'],
        ]);

        $type = QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test Uni',
            'qualification_holder_name' => 'Jane Doe',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ]);

        return [$user, $application, $qualification];
    }

    /**
     * @return array{0: Qualification, 1: User, 2: QualificationType, 3: User}
     */
    private function makeLevel2ReadyQualification(bool $withLevel2Owner = false): array
    {
        $type = QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();
        $level1 = $this->makeLevel1();
        $level2 = $this->makeLevel2();

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-L1UPL-'.rand(1000, 9999),
            'applicant_user_id' => User::factory()->activated()->create(['applicant_type' => 'individual'])->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => 'submitted',
            'verification_state' => VerificationState::AwaitingAssignment,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
            'service_deadline_at' => now()->addDays(14),
            'paid_at' => now(),
        ]);

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test Uni',
            'qualification_holder_name' => 'Jane Doe',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'assigned_verifier_id' => $level1->id,
            'verification_state' => VerificationState::UnderLevel1Review,
        ]);

        if ($withLevel2Owner) {
            app(AssignmentService::class)->assign($qualification, $level2, $level1, 'Please review.');
            app(QualificationLevel1ReviewService::class)->completeLevel1(
                qualification: $qualification,
                actor: $level1,
                findings: 'Initial findings.',
                recommendedForAward: false,
                qualificationTypeId: $type->id,
            );

            $qualification->refresh();
            $qualification->forceFill(['level2_review_owner_id' => $level2->id])->save();
        }

        return [$qualification->fresh(), $level1, $type, $level2];
    }

    private function makeLevel1(): User
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->assignRole('Verification Officer Level 1');
        $user->givePermissionTo(['verification.level1.process', 'verification.pool.view', 'dashboard.view']);

        return $user;
    }

    private function makeLevel2(): User
    {
        $user = User::factory()->activated()->create(['applicant_type' => null, 'email' => 'l2-upl-'.uniqid().'@example.test']);
        $user->assignRole('Verification Officer Level 2');
        $user->givePermissionTo(['verification.level2.review', 'verification.pool.view', 'dashboard.view']);

        return $user;
    }
}
