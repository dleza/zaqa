<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\LearnerRecordSourceType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\QualificationTitleSource;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\LearnerRecord;
use App\Models\Payment;
use App\Models\Qualification;
use App\Models\QualificationTitle;
use App\Models\QualificationType;
use App\Models\User;
use App\Domain\Fees\QualificationFeeResolver;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdfWrapper;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\FeeStructuresSeeder;
use Database\Seeders\QualificationTypesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class VerifiedQualificationIngestionTest extends TestCase
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

    public function test_certificate_issue_creates_learner_record_and_promotes_other_title(): void
    {
        Storage::fake('local');
        Mail::fake();
        $this->mockPdf();

        [$qualification] = $this->approvedQualification([
            'title_of_qualification' => 'Custom Diploma in Applied Analytics',
            'names_as_on_qualification_document' => 'Mary C. Mwansa',
            'applicant_entered_qualification_title' => 'Custom Diploma in Applied Analytics',
            'qualification_title_source' => QualificationTitleSource::Other,
            'certificate_number' => 'CERT-NEW-001',
        ]);

        $admin = $this->certificateAdmin();
        $this->actingAs($admin)->post(
            route('admin.verification.qualifications.issue_certificate', $qualification),
        )->assertRedirect();

        $qualification->refresh();
        $this->assertNotNull($qualification->learner_record_id);
        $this->assertNotNull($qualification->qualification_title_id);
        $this->assertSame('Custom Diploma in Applied Analytics', $qualification->verified_qualification_title);
        $this->assertSame(QualificationTitleSource::VerifiedPromoted, $qualification->qualification_title_source);

        $this->assertDatabaseHas('learner_records', [
            'id' => $qualification->learner_record_id,
            'program_of_study' => 'Custom Diploma in Applied Analytics',
            'source_type' => LearnerRecordSourceType::ZaqaVerification->value,
            'certificate_no' => 'CERT-NEW-001',
        ]);

        $this->assertDatabaseHas('qualification_titles', [
            'id' => $qualification->qualification_title_id,
            'name' => 'Custom Diploma in Applied Analytics',
            'is_active' => true,
        ]);
    }

    public function test_certificate_issue_creates_learner_record_for_catalog_title_without_existing_record(): void
    {
        Storage::fake('local');
        Mail::fake();
        $this->mockPdf();

        $title = QualificationTitle::query()->create([
            'name' => 'Diploma in Business Administration',
            'is_active' => true,
        ]);

        [$qualification] = $this->approvedQualification([
            'title_of_qualification' => $title->name,
            'names_as_on_qualification_document' => 'Mary C. Mwansa',
            'qualification_title_id' => $title->id,
            'qualification_title_source' => QualificationTitleSource::Catalog,
            'certificate_number' => 'CERT-CAT-002',
        ]);

        $this->assertSame(0, LearnerRecord::query()->count());

        $this->actingAs($this->certificateAdmin())->post(
            route('admin.verification.qualifications.issue_certificate', $qualification),
        )->assertRedirect();

        $qualification->refresh();
        $this->assertNotNull($qualification->learner_record_id);
        $this->assertSame($title->id, $qualification->qualification_title_id);
        $this->assertDatabaseCount('qualification_titles', 1);
        $this->assertDatabaseHas('learner_records', [
            'id' => $qualification->learner_record_id,
            'program_of_study' => $title->name,
        ]);
    }

    public function test_certificate_issue_links_existing_learner_record_by_dedupe_hash(): void
    {
        Storage::fake('local');
        Mail::fake();
        $this->mockPdf();

        [$qualification, $institution] = $this->approvedQualification([
            'title_of_qualification' => 'Existing Program',
            'names_as_on_qualification_document' => 'Mary C. Mwansa',
            'certificate_number' => 'CERT-DEDUPE-003',
        ]);

        $existing = LearnerRecord::query()->create([
            'awarding_institution_id' => $institution->id,
            'student_id' => null,
            'certificate_no' => 'CERT-DEDUPE-003',
            'program_of_study' => 'Old Program Name',
            'source_type' => LearnerRecordSourceType::Import,
            'certificate_no_normalized' => \App\Support\Normalization\LearnerRecordNormalizer::normalizeCertificateNo('CERT-DEDUPE-003'),
            'dedupe_hash' => \App\Support\Normalization\LearnerRecordNormalizer::dedupeHash(
                $institution->id,
                \App\Support\Normalization\LearnerRecordNormalizer::normalizeCertificateNo('CERT-DEDUPE-003'),
                null,
                (int) $qualification->award_date->format('Y'),
            ),
            'is_active' => true,
        ]);

        $this->actingAs($this->certificateAdmin())->post(
            route('admin.verification.qualifications.issue_certificate', $qualification),
        )->assertRedirect();

        $qualification->refresh();
        $this->assertSame($existing->id, $qualification->learner_record_id);
        $this->assertSame(1, LearnerRecord::query()->count());
    }

    public function test_manual_review_page_shows_new_title_prompt_for_other_title(): void
    {
        [$qualification] = $this->approvedQualification([
            'verification_state' => VerificationState::UnderLevel2Review,
            'title_of_qualification' => 'Brand New Qualification Title',
            'names_as_on_qualification_document' => 'Mary C. Mwansa',
            'applicant_entered_qualification_title' => 'Brand New Qualification Title',
            'qualification_title_source' => QualificationTitleSource::Other,
            'certificate_number' => 'CERT-PROMPT-004',
        ]);

        $reviewer = User::factory()->activated()->create(['applicant_type' => null]);
        $reviewer->givePermissionTo(['dashboard.view', 'verification.pool.view', 'verification.level2.review']);

        $this->actingAs($reviewer)->get(
            route('admin.verification.qualifications.show', $qualification),
        )->assertOk()->assertInertia(fn ($page) => $page
            ->component('Admin/Verification/Qualifications/Show')
            ->where('qualification.title_catalog.show_new_title_prompt', true)
            ->where('qualification.title_catalog.is_applicant_other', true)
        );
    }

    public function test_legacy_qualification_submission_without_title_source_still_works(): void
    {
        $user = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-LEG-'.random_int(1000, 9999),
            'applicant_user_id' => $user->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Draft,
            'is_foreign' => false,
            'metadata' => [],
        ]);

        $country = Country::query()->firstOrCreate(
            ['iso_code' => 'ZMB'],
            ['name' => 'Zambia', 'is_active' => true, 'sort_order' => 1],
        );
        $institution = AwardingInstitution::query()->create([
            'country_id' => $country->id,
            'name' => 'Legacy Institution',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $type = QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();

        $this->actingAs($user)->post("/applicant/applications/{$application->id}/qualifications", [
            'country_id' => $country->id,
            'awarding_institution_id' => $institution->id,
            'awarding_institution_name' => $institution->name,
            'qualification_type_id' => $type->id,
            'title_of_qualification' => 'Legacy Free Text Title',
            'names_as_on_qualification_document' => 'Mary C. Mwansa',
            'award_date' => now()->subYear()->toDateString(),
            'certificate_number' => 'LEG-001',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('qualifications', [
            'application_id' => $application->id,
            'title_of_qualification' => 'Legacy Free Text Title',
            'names_as_on_qualification_document' => 'Mary C. Mwansa',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{0: Qualification, 1: AwardingInstitution}
     */
    private function approvedQualification(array $overrides = []): array
    {
        $type = QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();
        $country = Country::query()->firstOrCreate(
            ['iso_code' => 'ZMB'],
            ['name' => 'Zambia', 'is_active' => true, 'sort_order' => 1],
        );
        $institution = AwardingInstitution::query()->create([
            'country_id' => $country->id,
            'name' => 'Verified Test Institution',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $applicant = User::factory()->activated()->create([
            'applicant_type' => 'individual',
            'email' => 'ingest-test-'.Str::lower((string) Str::ulid()).'@example.test',
        ]);

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-ING-'.random_int(10000, 99999),
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
            'awarding_institution_id' => $institution->id,
            'awarding_institution_name' => $institution->name,
            'qualification_holder_name' => 'Jane Holder',
            'country_id' => $country->id,
            'nrc_passport_number' => '999999/99/9',
            'title_of_qualification' => 'Diploma in Testing',
            'names_as_on_qualification_document' => 'Mary C. Mwansa',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'verification_state' => VerificationState::ApprovedForCertificate,
            'certificate_number' => 'CERT-DEFAULT-999',
        ], $overrides));

        $application->refresh()->load('qualifications');
        $required = app(QualificationFeeResolver::class)->totalVerificationFeesCents($application);
        Payment::query()->create([
            'application_id' => $application->id,
            'method' => PaymentMethod::Card,
            'status' => PaymentStatus::Confirmed,
            'currency' => 'ZMW',
            'amount_cents' => max(1, $required),
            'provider' => 'test',
            'confirmed_at' => now(),
        ]);

        return [$qualification->fresh(), $institution];
    }

    private function certificateAdmin(): User
    {
        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->givePermissionTo([
            'verification.pool.view',
            'verification.certificate.issue',
            'dashboard.view',
        ]);

        return $admin;
    }

    private function mockPdf(): void
    {
        $pdfMock = Mockery::mock(DomPdfWrapper::class);
        $pdfMock->shouldReceive('setPaper')->andReturnSelf();
        $pdfMock->shouldReceive('output')->andReturn('%PDF-test-output');
        Pdf::shouldReceive('loadView')->andReturn($pdfMock);
    }
}
