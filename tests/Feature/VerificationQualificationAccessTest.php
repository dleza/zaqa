<?php

namespace Tests\Feature;

use App\Domain\Applications\QualificationCaptureService;
use App\Domain\Verification\AssignmentService;
use App\Domain\Verification\QualificationLevel1ReviewService;
use App\Enums\ApplicationStatus;
use App\Enums\DocumentType;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\Qualification;
use App\Models\QualificationType;
use App\Models\User;
use App\Models\VerificationAssignmentCategoryUser;
use Database\Seeders\AwardingInstitutionsSeeder;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\FeeStructuresSeeder;
use Database\Seeders\QualificationTypesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class VerificationQualificationAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeSubmittedApplication(): Application
    {
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        return Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-VER-'.rand(10000, 99999),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Submitted,
            'verification_state' => VerificationState::AwaitingAssignment,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
            'service_deadline_at' => now()->addDays(14),
        ]);
    }

    public function test_level1_cannot_view_qualification_assigned_to_another_officer(): void
    {
        $application = $this->makeSubmittedApplication();

        $assignedOfficer = User::factory()->activated()->create(['applicant_type' => null]);
        $assignedOfficer->givePermissionTo(['verification.level1.process', 'verification.pool.view', 'dashboard.view']);

        $otherOfficer = User::factory()->activated()->create(['applicant_type' => null]);
        $otherOfficer->givePermissionTo(['verification.level1.process', 'verification.pool.view', 'dashboard.view']);

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test',
            'qualification_holder_name' => 'Jane',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'assigned_verifier_id' => $assignedOfficer->id,
        ]);

        $this->actingAs($otherOfficer)
            ->get(route('admin.verification.qualifications.show', ['qualification' => $qualification->id]))
            ->assertForbidden();
    }

    public function test_level1_can_view_own_assigned_qualification(): void
    {
        $application = $this->makeSubmittedApplication();

        $officer = User::factory()->activated()->create(['applicant_type' => null]);
        $officer->givePermissionTo(['verification.level1.process', 'verification.pool.view', 'dashboard.view']);

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test',
            'qualification_holder_name' => 'Jane',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'assigned_verifier_id' => $officer->id,
        ]);

        $this->actingAs($officer)
            ->get(route('admin.verification.qualifications.show', ['qualification' => $qualification->id]))
            ->assertOk();
    }

    public function test_level2_can_view_qualification_assigned_to_someone_else(): void
    {
        $application = $this->makeSubmittedApplication();

        $level1 = User::factory()->activated()->create(['applicant_type' => null]);
        $level1->givePermissionTo(['verification.level1.process', 'verification.pool.view']);

        $level2 = User::factory()->activated()->create(['applicant_type' => null]);
        $level2->givePermissionTo([
            'verification.assign',
            'verification.pool.view',
            'verification.level2.review',
            'dashboard.view',
        ]);

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test',
            'qualification_holder_name' => 'Jane',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'assigned_verifier_id' => $level1->id,
        ]);

        $this->actingAs($level2)
            ->get(route('admin.verification.qualifications.show', ['qualification' => $qualification->id]))
            ->assertOk();
    }

    public function test_qualification_pool_for_level1_only_lists_assigned_tasks(): void
    {
        $application = $this->makeSubmittedApplication();

        $mine = User::factory()->activated()->create(['applicant_type' => null]);
        $mine->givePermissionTo(['verification.level1.process', 'verification.pool.view', 'dashboard.view']);

        $other = User::factory()->activated()->create(['applicant_type' => null]);

        $qMine = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test',
            'qualification_holder_name' => 'A',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => 'Diploma A',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'assigned_verifier_id' => $mine->id,
        ]);

        Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test',
            'qualification_holder_name' => 'B',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '222222/22/2',
            'title_of_qualification' => 'Diploma B',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'assigned_verifier_id' => $other->id,
        ]);

        $page = $this->actingAs($mine)->get(route('admin.verification.pool.index'));

        $page->assertOk();
        $page->assertInertia(fn ($page) => $page
            ->has('qualifications.data', 1)
            ->where('qualifications.data.0.id', $qMine->id));
    }

    public function test_level1_cannot_open_parent_application_without_assigned_qualification(): void
    {
        $application = $this->makeSubmittedApplication();

        $officer = User::factory()->activated()->create(['applicant_type' => null]);
        $officer->givePermissionTo(['verification.level1.process', 'verification.pool.view', 'dashboard.view']);

        $other = User::factory()->activated()->create(['applicant_type' => null]);

        Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test',
            'qualification_holder_name' => 'B',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '222222/22/2',
            'title_of_qualification' => 'Diploma B',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'assigned_verifier_id' => $other->id,
        ]);

        $this->actingAs($officer)
            ->get(route('admin.verification.applications.show', ['application' => $application->id]))
            ->assertForbidden();
    }

    public function test_level2_can_revoke_level1_assignment(): void
    {
        $application = $this->makeSubmittedApplication();

        $level1 = User::factory()->activated()->create(['applicant_type' => null]);
        $level2 = User::factory()->activated()->create(['applicant_type' => null]);
        $level2->givePermissionTo(['verification.assign', 'verification.pool.view', 'dashboard.view']);

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test',
            'qualification_holder_name' => 'Jane',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'assigned_verifier_id' => $level1->id,
            'assigned_at' => now(),
            'verification_state' => VerificationState::AssignedToLevel1,
        ]);

        $this->actingAs($level2)
            ->post(route('admin.verification.qualifications.revoke_assignment', ['qualification' => $qualification->id]), [
                'comment' => 'Needs different reviewer.',
            ])
            ->assertRedirect();

        $qualification->refresh();
        $this->assertNull($qualification->assigned_verifier_id);
        $this->assertSame(VerificationState::AwaitingAssignment, $qualification->verification_state);
    }

    public function test_level1_cannot_revoke_assignment(): void
    {
        $application = $this->makeSubmittedApplication();

        $level1 = User::factory()->activated()->create(['applicant_type' => null]);
        $level1->givePermissionTo(['verification.level1.process', 'verification.pool.view', 'dashboard.view']);

        $other = User::factory()->activated()->create(['applicant_type' => null]);

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test',
            'qualification_holder_name' => 'Jane',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'assigned_verifier_id' => $other->id,
            'assigned_at' => now(),
            'verification_state' => VerificationState::AssignedToLevel1,
        ]);

        $this->actingAs($level1)
            ->post(route('admin.verification.qualifications.revoke_assignment', ['qualification' => $qualification->id]))
            ->assertForbidden();
    }

    public function test_level1_complete_stores_optional_attachment(): void
    {
        $this->seed([\Database\Seeders\BillingCategoriesSeeder::class, \Database\Seeders\QualificationTypesSeeder::class]);
        $type = \App\Models\QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();
        $application = $this->makeSubmittedApplication();

        $officer = User::factory()->activated()->create(['applicant_type' => null]);
        $officer->givePermissionTo(['verification.level1.process', 'verification.pool.view', 'dashboard.view']);

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test',
            'qualification_holder_name' => 'Jane',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'assigned_verifier_id' => $officer->id,
            'verification_state' => VerificationState::UnderLevel1Review,
        ]);

        $file = UploadedFile::fake()->create('l1-notes.pdf', 100, 'application/pdf');

        $this->actingAs($officer)
            ->post(route('admin.verification.qualifications.level1_complete', ['qualification' => $qualification->id]), [
                'qualification_type_id' => $type->id,
                'recommended_for_award' => '0',
                'findings' => 'All checks done; ready for Level 2.',
                'attachment' => $file,
            ])
            ->assertRedirect();

        $qualification->refresh();
        $this->assertSame(VerificationState::UnderLevel2Review, $qualification->verification_state);

        $this->assertDatabaseHas('qualification_documents', [
            'application_id' => $application->id,
            'qualification_id' => $qualification->id,
            'document_type' => DocumentType::Level1ReviewAttachment->value,
        ]);
    }

    public function test_level2_can_update_qualification_details_and_audit_logs(): void
    {
        $this->seed([
            BillingCategoriesSeeder::class,
            QualificationTypesSeeder::class,
            CountriesSeeder::class,
            AwardingInstitutionsSeeder::class,
        ]);

        $application = $this->makeSubmittedApplication();

        $zambia = Country::query()->where('iso_code', 'ZMB')->firstOrFail();
        $type = QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();
        $inst = AwardingInstitution::query()->where('country_id', $zambia->id)->firstOrFail();

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_id' => $inst->id,
            'awarding_institution_name' => $inst->name,
            'qualification_holder_name' => 'Jane Doe',
            'names_as_on_qualification_document' => 'JANE DOE',
            'country_id' => $zambia->id,
            'country_name_other' => null,
            'nrc_passport_number' => '111111/11/1',
            'certificate_number' => 'CERT-ORIG',
            'student_number' => null,
            'examination_number' => null,
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'transcript_reason' => 'Applicant note kept',
            'notes' => 'Internal verifier note kept',
        ]);

        $level2 = User::factory()->activated()->create(['applicant_type' => null]);
        $level2->givePermissionTo(['verification.level2.review', 'verification.pool.view', 'dashboard.view']);

        $payload = [
            'qualification_holder_name' => 'Jane D. Corrected',
            'names_as_on_qualification_document' => 'JANE D. CORRECTED',
            'nrc_passport_number' => '111111/11/1',
            'country_id' => $zambia->id,
            'country_name_other' => null,
            'awarding_institution_id' => $inst->id,
            'awarding_institution_name_other' => null,
            'awarding_institution_name' => $inst->name,
            'certificate_number' => 'CERT-UPD',
            'student_number' => null,
            'examination_number' => null,
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->format('Y-m-d'),
            'qualification_type_id' => $type->id,
            'correction_note' => 'Fixed certificate number.',
            'subject_results' => [],
        ];

        $this->actingAs($level2)
            ->put(route('admin.verification.qualifications.update', ['qualification' => $qualification->id]), $payload)
            ->assertRedirect(route('admin.verification.qualifications.show', $qualification));

        $qualification->refresh();
        $this->assertSame('Jane D. Corrected', $qualification->qualification_holder_name);
        $this->assertSame('JANE D. CORRECTED', $qualification->names_as_on_qualification_document);
        $this->assertSame('CERT-UPD', $qualification->certificate_number);
        $this->assertSame('Applicant note kept', $qualification->transcript_reason);
        $this->assertSame('Internal verifier note kept', $qualification->notes);

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'verification.qualification_corrected',
            'entity_id' => $qualification->id,
        ]);

        $audit = \App\Models\AuditLog::query()
            ->where('event_type', 'verification.qualification_corrected')
            ->where('entity_id', $qualification->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($audit);
        $metadata = (array) ($audit->metadata ?? []);
        $fieldChanges = $metadata['field_changes'] ?? [];
        $this->assertNotEmpty($fieldChanges);
        $afterState = (array) ($audit->after_state ?? []);
        $this->assertArrayHasKey('workflow', $afterState);
    }

    public function test_edit_page_includes_documents_and_admin_can_replace_certificate_with_audit(): void
    {
        $this->seed([
            BillingCategoriesSeeder::class,
            QualificationTypesSeeder::class,
            CountriesSeeder::class,
            AwardingInstitutionsSeeder::class,
        ]);

        $application = $this->makeSubmittedApplication();
        $zambia = Country::query()->where('iso_code', 'ZMB')->firstOrFail();
        $type = QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();
        $inst = AwardingInstitution::query()->where('country_id', $zambia->id)->firstOrFail();

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_id' => $inst->id,
            'awarding_institution_name' => $inst->name,
            'qualification_holder_name' => 'Jane Doe',
            'names_as_on_qualification_document' => 'JANE DOE (CERT)',
            'country_id' => $zambia->id,
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'verification_state' => VerificationState::UnderLevel1Review,
        ]);

        $existing = \App\Models\QualificationDocument::query()->create([
            'application_id' => $application->id,
            'qualification_id' => $qualification->id,
            'document_type' => DocumentType::CertificateCopy,
            'original_name' => 'old-cert.pdf',
            'stored_name' => 'old-cert.pdf',
            'disk' => 'local',
            'path' => 'private/test/old-cert.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 100,
            'sha256_hash' => str_repeat('a', 64),
            'visibility' => 'private',
            'version_number' => 1,
            'is_current_version' => true,
            'uploaded_by_user_id' => $application->applicant_user_id,
        ]);

        $level2 = User::factory()->activated()->create(['applicant_type' => null]);
        $level2->givePermissionTo(['verification.level2.review', 'verification.pool.view', 'dashboard.view']);

        $this->actingAs($level2)
            ->get(route('admin.verification.qualifications.edit', ['qualification' => $qualification->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('documents', 1)
                ->where('documents.0.id', $existing->id)
                ->has('identity_document')
                ->has('expected_document_types')
                ->where('expected_document_types.0', DocumentType::CertificateCopy->value)
                ->where('qualification.names_as_on_qualification_document', 'JANE DOE (CERT)'));

        $beforeState = $qualification->verification_state;

        $this->actingAs($level2)
            ->post(route('admin.verification.qualifications.documents.store', ['qualification' => $qualification->id]), [
                'document_type' => DocumentType::CertificateCopy->value,
                'file' => UploadedFile::fake()->create('new-cert.pdf', 100, 'application/pdf'),
                'correction_note' => 'Clearer scan uploaded.',
            ])
            ->assertRedirect(route('admin.verification.qualifications.edit', $qualification));

        $qualification->refresh();
        $this->assertSame($beforeState, $qualification->verification_state);

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'verification.qualification_document_uploaded',
            'entity_id' => $qualification->id,
        ]);

        $this->actingAs($level2)
            ->delete(route('admin.verification.qualifications.documents.destroy', [
                'qualification' => $qualification->id,
                'document' => $existing->id,
            ]), [
                'correction_note' => 'Removed duplicate scan.',
            ])
            ->assertRedirect(route('admin.verification.qualifications.edit', $qualification));

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'verification.qualification_document_deleted',
            'entity_id' => $qualification->id,
        ]);
    }

    public function test_edit_page_expected_document_types_include_transcript_and_consent_when_required(): void
    {
        $this->seed([
            BillingCategoriesSeeder::class,
            QualificationTypesSeeder::class,
            CountriesSeeder::class,
            AwardingInstitutionsSeeder::class,
        ]);

        $application = $this->makeSubmittedApplication();
        $type = QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Foreign Uni',
            'qualification_holder_name' => 'Jane Doe',
            'country_name_other' => 'United Kingdom',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => 'Degree',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => true,
            'transcript_required' => true,
            'verification_state' => VerificationState::UnderLevel1Review,
        ]);

        $level2 = User::factory()->activated()->create(['applicant_type' => null]);
        $level2->givePermissionTo(['verification.level2.review', 'verification.pool.view', 'dashboard.view']);

        $this->actingAs($level2)
            ->get(route('admin.verification.qualifications.edit', ['qualification' => $qualification->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('expected_document_types', [
                    DocumentType::CertificateCopy->value,
                    DocumentType::Transcript->value,
                    DocumentType::ConsentFormSigned->value,
                ]));
    }

    public function test_level1_cannot_open_edit_when_qualification_not_assigned_to_them(): void
    {
        $application = $this->makeSubmittedApplication();

        $officer = User::factory()->activated()->create(['applicant_type' => null]);
        $officer->givePermissionTo(['verification.level1.process', 'verification.pool.view', 'dashboard.view']);

        $other = User::factory()->activated()->create(['applicant_type' => null]);

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test',
            'qualification_holder_name' => 'Jane',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'assigned_verifier_id' => $other->id,
        ]);

        $this->actingAs($officer)
            ->get(route('admin.verification.qualifications.edit', ['qualification' => $qualification->id]))
            ->assertForbidden();
    }

    public function test_level1_redirects_to_assigned_list_after_qualification_send_back(): void
    {
        $application = $this->makeSubmittedApplication();

        $officer = User::factory()->activated()->create(['applicant_type' => null]);
        $officer->givePermissionTo([
            'verification.level1.process',
            'verification.pool.view',
            'verification.send_back',
            'dashboard.view',
        ]);

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test Uni',
            'qualification_holder_name' => 'Jane',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'assigned_verifier_id' => $officer->id,
            'verification_state' => VerificationState::UnderLevel1Review,
        ]);

        $response = $this->actingAs($officer)
            ->post(route('admin.verification.qualifications.send_back', ['qualification' => $qualification->id]), [
                'comment' => 'Please upload a clearer certificate scan.',
            ]);

        $response->assertRedirect(route('admin.verification.assigned_to_me'));
        $response->assertSessionHas('success');

        $qualification->refresh();
        $this->assertSame(VerificationState::ReturnedToApplicant, $qualification->verification_state);
        $this->assertNull($qualification->assigned_verifier_id);
    }

    public function test_level2_redirects_to_pool_after_qualification_send_back(): void
    {
        $application = $this->makeSubmittedApplication();

        $level2 = User::factory()->activated()->create(['applicant_type' => null]);
        $level2->givePermissionTo([
            'verification.level2.review',
            'verification.send_back',
            'verification.pool.view',
            'dashboard.view',
        ]);

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test Uni',
            'qualification_holder_name' => 'Jane',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '222222/22/2',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'assigned_verifier_id' => null,
            'verification_state' => VerificationState::UnderLevel2Review,
        ]);

        $response = $this->actingAs($level2)
            ->post(route('admin.verification.qualifications.send_back', ['qualification' => $qualification->id]), [
                'comment' => 'Applicant must correct institution details.',
            ]);

        $response->assertRedirect(route('admin.verification.pool.index'));
        $response->assertSessionHas('success');

        $qualification->refresh();
        $this->assertSame(VerificationState::ReturnedToApplicant, $qualification->verification_state);
    }

    public function test_awaiting_applicant_resubmission_lists_only_send_back_officer_qualifications(): void
    {
        $application = $this->makeSubmittedApplication();

        $officerA = User::factory()->activated()->create(['applicant_type' => null]);
        $officerA->givePermissionTo([
            'verification.pool.view',
            'verification.send_back',
            'dashboard.view',
        ]);

        $officerB = User::factory()->activated()->create(['applicant_type' => null]);
        $officerB->givePermissionTo([
            'verification.pool.view',
            'verification.send_back',
            'dashboard.view',
        ]);

        $qForA = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test',
            'qualification_holder_name' => 'A',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => 'Diploma A',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'verification_state' => VerificationState::ReturnedToApplicant,
            'send_back_by_user_id' => $officerA->id,
        ]);

        Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test',
            'qualification_holder_name' => 'B',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '222222/22/2',
            'title_of_qualification' => 'Diploma B',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'verification_state' => VerificationState::ReturnedToApplicant,
            'send_back_by_user_id' => $officerB->id,
        ]);

        $this->actingAs($officerA)
            ->get(route('admin.verification.awaiting_applicant_resubmission'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('qualifications.data', 1)
                ->where('qualifications.data.0.id', $qForA->id));
    }

    public function test_level2_assigned_to_me_includes_qualifications_owned_under_level2_review(): void
    {
        $application = $this->makeSubmittedApplication();

        $level2 = User::factory()->activated()->create(['applicant_type' => null]);
        $level2->givePermissionTo([
            'verification.level2.review',
            'verification.pool.view',
            'dashboard.view',
        ]);

        $qOwned = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test',
            'qualification_holder_name' => 'Jane',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '333333/33/3',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'assigned_verifier_id' => null,
            'verification_state' => VerificationState::UnderLevel2Review,
            'level2_review_owner_id' => $level2->id,
            'service_deadline_at' => now()->addDays(14),
        ]);

        $this->actingAs($level2)
            ->get(route('admin.verification.assigned_to_me'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('qualifications.data', 1)
                ->where('qualifications.data.0.id', $qOwned->id)
                ->where('qualifications.data.0.service_deadline_at', $qOwned->service_deadline_at?->toIso8601String()));
    }

    public function test_level2_assigner_sees_qualification_on_assigned_to_me_after_level1_completes(): void
    {
        $this->seed([\Database\Seeders\BillingCategoriesSeeder::class, \Database\Seeders\QualificationTypesSeeder::class]);
        $type = \App\Models\QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();
        $application = $this->makeSubmittedApplication();

        $zmb = \App\Models\Country::query()->create(['iso_code' => 'ZMB', 'name' => 'Zambia', 'is_active' => true, 'sort_order' => 0]);
        $inst = \App\Models\AwardingInstitution::query()->create(['country_id' => $zmb->id, 'name' => 'Test Uni', 'is_active' => true, 'sort_order' => 0]);
        $category = \App\Models\VerificationAssignmentCategory::query()->create([
            'name' => 'Local Test',
            'type' => 'local_institution',
            'is_active' => true,
        ]);
        $category->awardingInstitutions()->attach($inst->id);

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_id' => $inst->id,
            'awarding_institution_name' => $inst->name,
            'qualification_holder_name' => 'Jane',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '555555/55/5',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'verification_assignment_category_id' => $category->id,
        ]);

        $level2 = User::factory()->activated()->create(['applicant_type' => null]);
        $level2->assignRole('Verification Officer Level 2');
        $level2->givePermissionTo([
            'verification.assign',
            'verification.level2.review',
            'verification.pool.view',
            'dashboard.view',
        ]);

        VerificationAssignmentCategoryUser::query()->create([
            'verification_assignment_category_id' => $category->id,
            'user_id' => $level2->id,
            'review_level' => 'level2',
            'is_active' => true,
            'is_available' => true,
        ]);

        $level1 = User::factory()->activated()->create(['applicant_type' => null]);
        $level1->givePermissionTo('verification.level1.process');

        /** @var AssignmentService $assignments */
        $assignments = $this->app->make(AssignmentService::class);
        $assignments->assign($qualification, $level2, $level1, 'Please review.');

        /** @var QualificationLevel1ReviewService $reviews */
        $reviews = $this->app->make(QualificationLevel1ReviewService::class);
        $reviews->completeLevel1($qualification, $level1, 'All documents match. Recommend approval.', false, (int) $qualification->qualification_type_id);

        $qualification->refresh();
        $this->assertSame(VerificationState::UnderLevel2Review, $qualification->verification_state);
        $this->assertSame($level2->id, $qualification->level2_review_owner_id);

        $otherLevel2 = User::factory()->activated()->create(['applicant_type' => null]);
        $otherLevel2->givePermissionTo([
            'verification.level2.review',
            'verification.pool.view',
            'dashboard.view',
        ]);

        $this->actingAs($otherLevel2)
            ->get(route('admin.verification.assigned_to_me'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('qualifications.data', 0));

        $this->actingAs($level2)
            ->get(route('admin.verification.assigned_to_me'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('qualifications.data', 1)
                ->where('qualifications.data.0.id', $qualification->id));
    }

    public function test_assigned_to_me_forbidden_without_level1_or_level2_process(): void
    {
        $viewer = User::factory()->activated()->create(['applicant_type' => null]);
        $viewer->givePermissionTo(['verification.pool.view', 'dashboard.view']);

        $this->actingAs($viewer)
            ->get(route('admin.verification.assigned_to_me'))
            ->assertForbidden();
    }

    public function test_reopen_after_applicant_amendment_restores_level2_review_owner(): void
    {
        $application = $this->makeSubmittedApplication();
        $application->forceFill(['current_status' => ApplicationStatus::SentBack])->save();

        $l2 = User::factory()->activated()->create(['applicant_type' => null]);
        $l2->givePermissionTo(['verification.level2.review', 'verification.pool.view']);

        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test Uni',
            'qualification_holder_name' => 'Jane',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '444444/44/4',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'verification_state' => VerificationState::ReturnedToApplicant,
            'send_back_by_user_id' => $l2->id,
            'send_back_reopen_level' => 'level2',
        ]);

        app(QualificationCaptureService::class)->reopenQualificationAfterApplicantAmendment($qualification->fresh(), $applicant);

        $qualification->refresh();
        $this->assertSame(VerificationState::UnderLevel2Review, $qualification->verification_state);
        $this->assertSame($l2->id, $qualification->level2_review_owner_id);
        $this->assertNull($qualification->send_back_by_user_id);
    }

    public function test_finalize_amendment_rejected_when_fee_not_fully_paid(): void
    {
        $this->seed(BillingCategoriesSeeder::class);
        $this->seed(QualificationTypesSeeder::class);
        $this->seed(FeeStructuresSeeder::class);

        $application = $this->makeSubmittedApplication();
        $applicant = User::query()->findOrFail($application->applicant_user_id);

        $qualificationTypeId = (int) QualificationType::query()->value('id');
        $this->assertGreaterThan(0, $qualificationTypeId);

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test Uni',
            'qualification_holder_name' => 'Jane',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '555555/55/5',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => $qualificationTypeId,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'verification_state' => VerificationState::ReturnedToApplicant,
        ]);

        $this->actingAs($applicant)
            ->post(route('applicant.applications.qualifications.finalize_amendment', [
                'application' => $application->id,
                'qualification' => $qualification->id,
            ]))
            ->assertSessionHasErrors('payment');
    }
}
