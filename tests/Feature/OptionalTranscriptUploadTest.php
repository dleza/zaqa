<?php

namespace Tests\Feature;

use App\Domain\Applications\ApplicationSubmissionReadinessService;
use App\Enums\ApplicantType;
use App\Enums\DocumentType;
use App\Enums\VerificationState;
use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\AwardingInstitution;
use App\Models\CertificateSubject;
use App\Models\Country;
use App\Models\Qualification;
use App\Models\QualificationDocument;
use App\Models\QualificationType;
use App\Models\User;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\FeeStructuresSeeder;
use Database\Seeders\QualificationTypesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class OptionalTranscriptUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BillingCategoriesSeeder::class);
        $this->seed(QualificationTypesSeeder::class);
        $this->seed(FeeStructuresSeeder::class);
        $this->seed(CountriesSeeder::class);
    }

    public function test_school_certificate_qualification_can_be_saved_without_transcript(): void
    {
        [$user, $application] = $this->makeDraftApplication();
        $type = QualificationType::query()->where('zqf_level_code', 'L2B')->firstOrFail();
        $subject = CertificateSubject::query()->create([
            'name' => 'Mathematics '.uniqid(),
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post("/applicant/applications/{$application->id}/qualifications", [
                'create_new' => true,
                'awarding_institution_name' => 'Test School',
                'qualification_holder_name' => 'John Doe',
                'country_name_other' => 'Zambia',
                'awarding_institution_name_other' => 'Test School',
                'nrc_passport_number' => '111111/11/1',
                'certificate_number' => 'CERT-GRADE12',
                'title_of_qualification' => 'Grade 12 Certificate',
                'names_as_on_qualification_document' => 'John Doe',
                'award_date' => now()->subYear()->toDateString(),
                'qualification_type_id' => $type->id,
                'subject_results' => [
                    ['certificate_subject_id' => $subject->id, 'grade' => '1'],
                ],
            ])
            ->assertRedirect();

        $qualification = $application->fresh('qualifications')->qualifications->firstOrFail();
        $this->assertTrue($qualification->transcript_required);
        $this->assertDatabaseMissing('qualification_documents', [
            'application_id' => $application->id,
            'qualification_id' => $qualification->id,
            'document_type' => DocumentType::Transcript->value,
        ]);
    }

    public function test_wizard_marks_qualification_complete_without_transcript(): void
    {
        [$user, $application, $qualification] = $this->makeSchoolQualificationWithCertificateOnly();

        $this->actingAs($user)
            ->get(route('applicant.applications.edit', ['application' => $application, 'step' => 'qualification']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('application.qualifications.0.transcript_required', true)
                ->where('application.qualifications.0.has_transcript_document', false)
                ->where('application.qualifications.0.has_certificate_document', true)
                ->where('application.qualifications.0.missing_requirements', fn ($missing) => ! in_array('transcript', (array) $missing, true))
            );
    }

    public function test_application_can_proceed_to_payment_without_transcript(): void
    {
        [$user, $application] = $this->makeSchoolQualificationWithCertificateOnly();

        $this->actingAs($user)->post("/applicant/applications/{$application->id}/documents", [
            'document_type' => 'nrc_copy',
            'file' => UploadedFile::fake()->image('nrc.png')->size(200),
        ])->assertRedirect();

        $this->actingAs($user)->patch("/applicant/applications/{$application->id}/wizard-declarations", [
            'accept_terms' => true,
            'confirm_information_correct' => true,
        ])->assertRedirect();

        app(ApplicationSubmissionReadinessService::class)->assertReadyForPayment($application->fresh(), $user);

        $this->assertTrue(true);
    }

    public function test_create_workspace_shows_transcript_requested_for_foreign_qualification_type(): void
    {
        [$user, $application] = $this->makeDraftApplication();

        $this->actingAs($user)
            ->get(route('applicant.applications.qualifications.workspace.create', ['application' => $application]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Applicant/Applications/Qualifications/Workspace')
                ->has('qualificationTypes')
            );
    }

    public function test_uploaded_transcript_validates_file_type(): void
    {
        [$user, $application, $qualification] = $this->makeForeignQualificationWithCertificateOnly();

        $this->actingAs($user)
            ->post("/applicant/applications/{$application->id}/documents", [
                'document_type' => 'transcript',
                'qualification_id' => $qualification->id,
                'file' => UploadedFile::fake()->create('transcript.exe', 10, 'application/x-msdownload'),
            ])
            ->assertSessionHasErrors(['file']);
    }

    public function test_uploaded_transcript_validates_max_size(): void
    {
        [$user, $application, $qualification] = $this->makeForeignQualificationWithCertificateOnly();
        $maxKb = (int) config('documents.max_upload_kb', 10240);

        $this->actingAs($user)
            ->post("/applicant/applications/{$application->id}/documents", [
                'document_type' => 'transcript',
                'qualification_id' => $qualification->id,
                'file' => UploadedFile::fake()->create('transcript.pdf', $maxKb + 1, 'application/pdf'),
            ])
            ->assertSessionHasErrors(['file']);
    }

    public function test_uploaded_transcript_saves_successfully(): void
    {
        [$user, $application, $qualification] = $this->makeForeignQualificationWithCertificateOnly();

        $this->actingAs($user)
            ->post("/applicant/applications/{$application->id}/documents", [
                'document_type' => 'transcript',
                'qualification_id' => $qualification->id,
                'file' => UploadedFile::fake()->create('transcript.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('qualification_documents', [
            'application_id' => $application->id,
            'qualification_id' => $qualification->id,
            'document_type' => DocumentType::Transcript->value,
            'is_current_version' => 1,
        ]);
    }

    public function test_missing_certificate_still_blocks_payment_readiness(): void
    {
        [$user, $application, $qualification] = $this->makeSchoolQualificationWithCertificateOnly();

        QualificationDocument::query()
            ->where('application_id', $application->id)
            ->where('qualification_id', $qualification->id)
            ->where('document_type', DocumentType::CertificateCopy->value)
            ->delete();

        $this->actingAs($user)->post("/applicant/applications/{$application->id}/documents", [
            'document_type' => 'nrc_copy',
            'file' => UploadedFile::fake()->image('nrc.png')->size(200),
        ])->assertRedirect();

        $this->actingAs($user)->patch("/applicant/applications/{$application->id}/wizard-declarations", [
            'accept_terms' => true,
            'confirm_information_correct' => true,
        ])->assertRedirect();

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(ApplicationSubmissionReadinessService::class)->assertReadyForPayment($application->fresh(), $user);
    }

    public function test_admin_edit_page_handles_missing_transcript_without_error(): void
    {
        [$user, $application, $qualification] = $this->makeForeignQualificationWithCertificateOnly();

        $level2 = User::factory()->activated()->create(['applicant_type' => null]);
        $level2->givePermissionTo(['verification.level2.review', 'verification.pool.view', 'dashboard.view']);

        $qualification->forceFill([
            'verification_state' => VerificationState::UnderLevel2Review,
        ])->save();

        $this->actingAs($level2)
            ->get(route('admin.verification.qualifications.edit', ['qualification' => $qualification->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('qualification.transcript_required', true)
                ->has('documents', 1)
                ->has('expected_document_types', 3)
            );
    }

    public function test_existing_qualification_with_transcript_still_displays_transcript(): void
    {
        [$user, $application, $qualification] = $this->makeForeignQualificationWithCertificateOnly();

        QualificationDocument::query()->create([
            'application_id' => $application->id,
            'qualification_id' => $qualification->id,
            'document_type' => DocumentType::Transcript->value,
            'original_name' => 'transcript.pdf',
            'stored_name' => 'transcript.pdf',
            'disk' => 'local',
            'path' => 'applications/'.$application->id.'/transcript.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 120,
            'sha256_hash' => hash('sha256', 'transcript'),
            'visibility' => 'private',
            'uploaded_by_user_id' => $user->id,
            'version_number' => 1,
            'is_current_version' => true,
        ]);

        $level2 = User::factory()->activated()->create(['applicant_type' => null]);
        $level2->givePermissionTo(['verification.level2.review', 'verification.pool.view', 'dashboard.view']);

        $qualification->forceFill([
            'verification_state' => VerificationState::UnderLevel2Review,
        ])->save();

        $this->actingAs($level2)
            ->get(route('admin.verification.qualifications.edit', ['qualification' => $qualification->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('documents', 2)
                ->where('documents', fn ($docs) => collect($docs)->contains(
                    fn ($d) => ($d['document_type'] ?? '') === DocumentType::Transcript->value
                ))
            );
    }

    /**
     * @return array{0: User, 1: Application}
     */
    private function makeDraftApplication(): array
    {
        $user = User::factory()->activated()->create([
            'applicant_type' => ApplicantType::Individual,
        ]);

        ApplicantProfile::create([
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
            'application_number' => 'ZAQA-OT-'.random_int(1000, 9999),
            'applicant_user_id' => $user->id,
            'applicant_type' => ApplicantType::Individual,
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => 'draft',
            'is_foreign' => false,
            'metadata' => ['submitting_for' => 'self'],
        ]);

        return [$user, $application];
    }

    /**
     * @return array{0: User, 1: Application, 2: Qualification}
     */
    private function makeSchoolQualificationWithCertificateOnly(): array
    {
        [$user, $application] = $this->makeDraftApplication();

        $country = Country::query()->where('iso_code', 'ZMB')->firstOrFail();
        $type = QualificationType::query()->where('zqf_level_code', 'L2B')->firstOrFail();
        $subject = CertificateSubject::query()->create([
            'name' => 'English '.uniqid(),
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'country_id' => $country->id,
            'awarding_institution_name' => 'Test School',
            'awarding_institution_name_other' => 'Test School',
            'qualification_holder_name' => 'John Doe',
            'nrc_passport_number' => '111111/11/1',
            'certificate_number' => 'CERT-GRADE12',
            'title_of_qualification' => 'Grade 12 Certificate',
            'names_as_on_qualification_document' => 'John Doe',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'transcript_required' => true,
        ]);

        $qualification->subjectResults()->create([
            'certificate_subject_id' => $subject->id,
            'subject_name' => $subject->name,
            'grade' => '1',
            'display_order' => 1,
        ]);

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
            'size_bytes' => 100,
            'sha256_hash' => hash('sha256', 'certificate'),
            'visibility' => 'private',
            'uploaded_by_user_id' => $user->id,
            'version_number' => 1,
            'is_current_version' => true,
        ]);

        return [$user, $application, $qualification];
    }

    /**
     * @return array{0: User, 1: Application, 2: Qualification}
     */
    private function makeForeignQualificationWithCertificateOnly(): array
    {
        [$user, $application] = $this->makeDraftApplication();

        $southAfricaId = (int) Country::query()->where('iso_code', 'ZAF')->value('id');
        $awardingInstitution = AwardingInstitution::query()->create([
            'country_id' => $southAfricaId,
            'name' => 'Foreign University',
            'is_active' => true,
            'sort_order' => 0,
            'consent_form_path' => 'private/awarding-institutions/seed/consent-form/template.pdf',
        ]);
        Storage::disk('local')->put($awardingInstitution->consent_form_path, 'template');

        $type = QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_id' => $awardingInstitution->id,
            'awarding_institution_name' => 'Foreign University',
            'qualification_holder_name' => 'John Doe',
            'country_id' => $southAfricaId,
            'country_name_other' => 'South Africa',
            'nrc_passport_number' => 'P1234567',
            'certificate_number' => 'CERT-FOREIGN',
            'title_of_qualification' => 'Bachelor of Testing',
            'names_as_on_qualification_document' => 'John Doe',
            'award_date' => now()->subYears(2)->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => true,
            'transcript_required' => true,
        ]);

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
            'size_bytes' => 100,
            'sha256_hash' => hash('sha256', 'certificate'),
            'visibility' => 'private',
            'uploaded_by_user_id' => $user->id,
            'version_number' => 1,
            'is_current_version' => true,
        ]);

        return [$user, $application, $qualification];
    }
}
