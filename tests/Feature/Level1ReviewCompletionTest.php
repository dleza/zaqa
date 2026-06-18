<?php

namespace Tests\Feature;

use App\Domain\Verification\AssignmentService;
use App\Domain\Verification\QualificationLevel1ReviewService;
use App\Enums\ApplicationStatus;
use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\Payment;
use App\Models\Qualification;
use App\Models\QualificationType;
use App\Models\User;
use App\Models\VerificationAssignmentCategory;
use App\Models\VerificationAssignmentCategoryUser;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdfWrapper;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\FeeStructuresSeeder;
use Database\Seeders\QualificationTypesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class Level1ReviewCompletionTest extends TestCase
{
    use RefreshDatabase;

    private function seedQualificationTypes(): void
    {
        $this->seed([
            BillingCategoriesSeeder::class,
            QualificationTypesSeeder::class,
            FeeStructuresSeeder::class,
        ]);
    }

    private function diplomaType(): QualificationType
    {
        $this->seedQualificationTypes();

        return QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();
    }

    private function mastersType(): QualificationType
    {
        $this->seedQualificationTypes();

        return QualificationType::query()->where('zqf_level_code', 'L9')->firstOrFail();
    }

    private function makeLevel1(): User
    {
        $u = User::factory()->activated()->create(['applicant_type' => null]);
        $u->assignRole('Verification Officer Level 1');
        $u->givePermissionTo(['verification.level1.process', 'verification.pool.view', 'dashboard.view']);

        return $u;
    }

    private function makeLevel2(): User
    {
        $u = User::factory()->activated()->create(['applicant_type' => null, 'email' => 'l2@example.test']);
        $u->assignRole('Verification Officer Level 2');
        $u->givePermissionTo(['verification.level2.review', 'verification.pool.view', 'dashboard.view']);

        return $u;
    }

    private function makeApplication(): Application
    {
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        return Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-L1-'.rand(1000, 9999),
            'applicant_user_id' => $applicant->id,
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
    }

    private function makeAssignedQualification(User $level1, ?QualificationType $type = null): Qualification
    {
        $type ??= $this->diplomaType();
        $application = $this->makeApplication();

        return Qualification::query()->create([
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
    }

    public function test_level1_completion_requires_qualification_type(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $level1 = $this->makeLevel1();
        $qualification = $this->makeAssignedQualification($level1);

        $this->actingAs($level1)
            ->post(route('admin.verification.qualifications.level1_complete', ['qualification' => $qualification->id]), [
                'recommended_for_award' => '0',
                'findings' => 'Checks complete.',
            ])
            ->assertSessionHasErrors('qualification_type_id');
    }

    public function test_level1_completion_requires_recommendation(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $level1 = $this->makeLevel1();
        $type = $this->diplomaType();
        $qualification = $this->makeAssignedQualification($level1, $type);

        $this->actingAs($level1)
            ->post(route('admin.verification.qualifications.level1_complete', ['qualification' => $qualification->id]), [
                'qualification_type_id' => $type->id,
                'findings' => 'Checks complete.',
            ])
            ->assertSessionHasErrors('recommended_for_award');
    }

    public function test_show_page_includes_qualification_type_options(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $level1 = $this->makeLevel1();
        $type = $this->diplomaType();
        $qualification = $this->makeAssignedQualification($level1, $type);

        $response = $this->actingAs($level1)
            ->get(route('admin.verification.qualifications.show', ['qualification' => $qualification->id]))
            ->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->where('qualification.qualification_type_id', $type->id)
            ->has('qualificationTypes'));

        $types = $response->original->getData()['page']['props']['qualificationTypes'] ?? [];
        $this->assertNotNull(collect($types)->firstWhere('id', $type->id));
    }

    public function test_recommend_awarding_requires_accreditation_statement(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $level1 = $this->makeLevel1();
        $type = $this->diplomaType();
        $qualification = $this->makeAssignedQualification($level1, $type);

        $this->actingAs($level1)
            ->post(route('admin.verification.qualifications.level1_complete', ['qualification' => $qualification->id]), [
                'qualification_type_id' => $type->id,
                'recommended_for_award' => '1',
                'findings' => 'All documents verified.',
            ])
            ->assertSessionHasErrors('accreditation_statement');
    }

    public function test_do_not_recommend_allows_optional_accreditation_statement(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $level1 = $this->makeLevel1();
        $type = $this->diplomaType();
        $qualification = $this->makeAssignedQualification($level1, $type);

        $this->actingAs($level1)
            ->post(route('admin.verification.qualifications.level1_complete', ['qualification' => $qualification->id]), [
                'qualification_type_id' => $type->id,
                'recommended_for_award' => '0',
                'findings' => 'Documents do not support award.',
            ])
            ->assertRedirect();

        $qualification->refresh();
        $this->assertFalse((bool) $qualification->level1_recommended_for_award);
        $this->assertNull($qualification->level1_accreditation_statement);
        $this->assertSame($type->id, (int) $qualification->qualification_type_id);
    }

    public function test_unchanged_qualification_type_completes_without_correction_audit(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $level1 = $this->makeLevel1();
        $type = $this->diplomaType();
        $qualification = $this->makeAssignedQualification($level1, $type);

        $this->actingAs($level1)
            ->post(route('admin.verification.qualifications.level1_complete', ['qualification' => $qualification->id]), [
                'qualification_type_id' => $type->id,
                'recommended_for_award' => '0',
                'findings' => 'No type change.',
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('audit_logs', [
            'entity_type' => Qualification::class,
            'entity_id' => $qualification->id,
            'event_type' => 'verification.level1_qualification_type_corrected',
        ]);
    }

    public function test_changed_qualification_type_updates_record_and_audits(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $level1 = $this->makeLevel1();
        $diploma = $this->diplomaType();
        $masters = $this->mastersType();
        $qualification = $this->makeAssignedQualification($level1, $diploma);

        $this->actingAs($level1)
            ->post(route('admin.verification.qualifications.level1_complete', ['qualification' => $qualification->id]), [
                'qualification_type_id' => $masters->id,
                'recommended_for_award' => '0',
                'findings' => 'Applicant selected wrong type.',
            ])
            ->assertRedirect();

        $qualification->refresh();
        $this->assertSame($masters->id, (int) $qualification->qualification_type_id);
        $this->assertSame($masters->zqf_level_code, $qualification->qualification_type);

        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => Qualification::class,
            'entity_id' => $qualification->id,
            'event_type' => 'verification.level1_qualification_type_corrected',
            'actor_user_id' => $level1->id,
        ]);

        $audit = AuditLog::query()
            ->where('event_type', 'verification.level1_qualification_type_corrected')
            ->where('entity_id', $qualification->id)
            ->firstOrFail();
        $metadata = (array) ($audit->metadata ?? []);
        $this->assertSame($diploma->id, (int) $metadata['old_qualification_type_id']);
        $this->assertSame($masters->id, (int) $metadata['new_qualification_type_id']);
        $this->assertTrue((bool) ($metadata['changed_during_level1_completion'] ?? false));
    }

    public function test_inactive_qualification_type_is_rejected(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $level1 = $this->makeLevel1();
        $diploma = $this->diplomaType();
        $masters = $this->mastersType();
        $masters->forceFill(['is_active' => false])->save();
        $qualification = $this->makeAssignedQualification($level1, $diploma);

        $this->actingAs($level1)
            ->post(route('admin.verification.qualifications.level1_complete', ['qualification' => $qualification->id]), [
                'qualification_type_id' => $masters->id,
                'recommended_for_award' => '0',
                'findings' => 'Attempt inactive type.',
            ])
            ->assertSessionHasErrors('qualification_type_id');

        $qualification->refresh();
        $this->assertSame($diploma->id, (int) $qualification->qualification_type_id);
    }

    public function test_invalid_qualification_type_is_rejected(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $level1 = $this->makeLevel1();
        $type = $this->diplomaType();
        $qualification = $this->makeAssignedQualification($level1, $type);

        $this->actingAs($level1)
            ->post(route('admin.verification.qualifications.level1_complete', ['qualification' => $qualification->id]), [
                'qualification_type_id' => 999999,
                'recommended_for_award' => '0',
                'findings' => 'Invalid type.',
            ])
            ->assertSessionHasErrors('qualification_type_id');
    }

    public function test_recommend_awarding_stores_submission_and_attachments(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $zmb = Country::query()->create(['iso_code' => 'ZMB', 'name' => 'Zambia', 'is_active' => true, 'sort_order' => 0]);
        $inst = AwardingInstitution::query()->create(['country_id' => $zmb->id, 'name' => 'Test Uni', 'is_active' => true, 'sort_order' => 0]);
        $category = VerificationAssignmentCategory::query()->create([
            'name' => 'Local Test',
            'type' => 'local_institution',
            'is_active' => true,
        ]);
        $category->awardingInstitutions()->attach($inst->id);

        $assigner = User::factory()->activated()->create(['applicant_type' => null, 'email' => 'assigner@example.test']);
        $assigner->assignRole('Verification Officer Level 2');
        $assigner->givePermissionTo(['verification.assign', 'verification.level2.review', 'verification.pool.view', 'dashboard.view']);
        $categoryL2 = User::factory()->activated()->create(['applicant_type' => null, 'email' => 'categoryl2@example.test']);
        $categoryL2->assignRole('Verification Officer Level 2');
        $categoryL2->givePermissionTo(['verification.level2.review', 'verification.pool.view', 'dashboard.view']);
        VerificationAssignmentCategoryUser::query()->create([
            'verification_assignment_category_id' => $category->id,
            'user_id' => $categoryL2->id,
            'review_level' => 'level2',
            'is_active' => true,
            'is_available' => true,
        ]);

        $level1 = $this->makeLevel1();
        $type = $this->diplomaType();
        $application = $this->makeApplication();
        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_id' => $inst->id,
            'awarding_institution_name' => $inst->name,
            'qualification_holder_name' => 'Jane Doe',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '222222/22/2',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'verification_assignment_category_id' => $category->id,
        ]);

        app(AssignmentService::class)->assign($qualification, $assigner, $level1);

        $evaluationReport = UploadedFile::fake()->create('evaluation.pdf', 120, 'application/pdf');
        $supporting = UploadedFile::fake()->create('notes.pdf', 80, 'application/pdf');

        $this->actingAs($level1)
            ->post(route('admin.verification.qualifications.level1_complete', ['qualification' => $qualification->id]), [
                'qualification_type_id' => $type->id,
                'recommended_for_award' => '1',
                'findings' => 'All checks passed.',
                'accreditation_statement' => 'Registered institution under the Zambia Qualifications Authority Act.',
                'evaluation_report' => $evaluationReport,
                'attachment' => $supporting,
            ])
            ->assertRedirect();

        $qualification->refresh();
        $this->assertTrue((bool) $qualification->level1_recommended_for_award);
        $this->assertSame('Registered institution under the Zambia Qualifications Authority Act.', $qualification->level1_accreditation_statement);
        $this->assertSame($level1->id, (int) $qualification->level1_review_completed_by_user_id);
        $this->assertSame($categoryL2->id, (int) $qualification->level2_review_owner_id);

        $this->assertDatabaseHas('qualification_documents', [
            'qualification_id' => $qualification->id,
            'document_type' => DocumentType::Level1EvaluationReport->value,
        ]);
        $this->assertDatabaseHas('qualification_documents', [
            'qualification_id' => $qualification->id,
            'document_type' => DocumentType::Level1ReviewAttachment->value,
        ]);
    }

    public function test_certificate_issue_uses_level1_accreditation_statement(): void
    {
        $this->seed([
            RolesAndPermissionsSeeder::class,
            BillingCategoriesSeeder::class,
            QualificationTypesSeeder::class,
            FeeStructuresSeeder::class,
        ]);

        Storage::fake('local');
        Mail::fake();

        $type = QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();
        $applicant = User::factory()->activated()->create([
            'applicant_type' => 'individual',
            'email' => 'cert-l1-'.Str::lower((string) Str::ulid()).'@example.test',
        ]);

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-CERT-'.random_int(10000, 99999),
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
            'verification_reference_number' => 'ZAQA-Q-'.Str::upper((string) Str::ulid()),
            'awarding_institution_name' => 'Test Institution',
            'qualification_holder_name' => 'Jane Holder',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '888888/88/8',
            'title_of_qualification' => $type->name,
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'verification_state' => VerificationState::ApprovedForCertificate,
            'level1_recommended_for_award' => true,
            'level1_accreditation_statement' => 'Custom accreditation statement for certificate.',
        ]);

        $application->refresh()->load('qualifications');
        $required = app(\App\Domain\Fees\QualificationFeeResolver::class)->totalVerificationFeesCents($application);
        Payment::query()->create([
            'application_id' => $application->id,
            'invoice_id' => null,
            'method' => PaymentMethod::Card,
            'status' => PaymentStatus::Confirmed,
            'currency' => 'ZMW',
            'amount_cents' => $required,
            'provider' => 'test',
            'confirmed_at' => now(),
        ]);

        $pdfMock = Mockery::mock(DomPdfWrapper::class);
        $pdfMock->shouldReceive('setPaper')->once()->with('A4', 'portrait')->andReturnSelf();
        $pdfMock->shouldReceive('output')->once()->andReturn('%PDF-test-output');
        Pdf::shouldReceive('loadView')
            ->once()
            ->withArgs(function (string $view, array $data) {
                return ($data['recognition_statement'] ?? '') === 'Custom accreditation statement for certificate.';
            })
            ->andReturn($pdfMock);

        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->givePermissionTo(['verification.pool.view', 'verification.certificate.issue', 'dashboard.view']);

        $this->actingAs($admin)
            ->post(route('admin.verification.qualifications.issue_certificate', $qualification), [])
            ->assertRedirect();
    }

    public function test_certificate_issue_uses_corrected_qualification_type(): void
    {
        $this->seed([
            RolesAndPermissionsSeeder::class,
            BillingCategoriesSeeder::class,
            QualificationTypesSeeder::class,
            FeeStructuresSeeder::class,
        ]);

        Storage::fake('local');
        Mail::fake();

        $diploma = $this->diplomaType();
        $masters = $this->mastersType();
        $applicant = User::factory()->activated()->create([
            'applicant_type' => 'individual',
            'email' => 'cert-type-'.Str::lower((string) Str::ulid()).'@example.test',
        ]);

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-CERT-TYPE-'.random_int(10000, 99999),
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
            'verification_reference_number' => 'ZAQA-Q-TYPE-'.Str::upper((string) Str::ulid()),
            'awarding_institution_name' => 'Test Institution',
            'qualification_holder_name' => 'Jane Holder',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '777777/77/7',
            'title_of_qualification' => 'Programme',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $masters->zqf_level_code,
            'qualification_type_id' => $masters->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'verification_state' => VerificationState::ApprovedForCertificate,
            'level1_recommended_for_award' => true,
            'level1_accreditation_statement' => 'Accredited body.',
        ]);

        $application->refresh()->load('qualifications');
        $required = app(\App\Domain\Fees\QualificationFeeResolver::class)->totalVerificationFeesCents($application);
        Payment::query()->create([
            'application_id' => $application->id,
            'invoice_id' => null,
            'method' => PaymentMethod::Card,
            'status' => PaymentStatus::Confirmed,
            'currency' => 'ZMW',
            'amount_cents' => $required,
            'provider' => 'test',
            'confirmed_at' => now(),
        ]);

        $pdfMock = Mockery::mock(DomPdfWrapper::class);
        $pdfMock->shouldReceive('setPaper')->once()->with('A4', 'portrait')->andReturnSelf();
        $pdfMock->shouldReceive('output')->once()->andReturn('%PDF-test-output');
        Pdf::shouldReceive('loadView')
            ->once()
            ->withArgs(function (string $view, array $data) use ($masters) {
                return ($data['recognised_zambian_qualification'] ?? '') === $masters->name;
            })
            ->andReturn($pdfMock);

        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->givePermissionTo(['verification.pool.view', 'verification.certificate.issue', 'dashboard.view']);

        $this->actingAs($admin)
            ->post(route('admin.verification.qualifications.issue_certificate', $qualification), [])
            ->assertRedirect();
    }

    public function test_level2_can_see_level1_submission_on_qualification_page(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $level1 = $this->makeLevel1();
        $level2 = $this->makeLevel2();
        $diploma = $this->diplomaType();
        $masters = $this->mastersType();
        $qualification = $this->makeAssignedQualification($level1, $diploma);

        app(QualificationLevel1ReviewService::class)->completeLevel1(
            qualification: $qualification,
            actor: $level1,
            findings: 'Verified against records.',
            recommendedForAward: true,
            qualificationTypeId: $masters->id,
            accreditationStatement: 'Accredited awarding body.',
            supportingAttachment: null,
            evaluationReport: UploadedFile::fake()->create('report.pdf', 100, 'application/pdf'),
        );

        $this->actingAs($level2)
            ->get(route('admin.verification.qualifications.show', ['qualification' => $qualification->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('qualification.qualification_type', $masters->name)
                ->where('qualification.level1_review.recommendation_label', 'Recommend recognition')
                ->where('qualification.level1_review.findings', 'Verified against records.')
                ->where('qualification.level1_review.accreditation_statement', 'Accredited awarding body.')
                ->where('qualification.level1_review.qualification_type_correction.message', "Qualification type changed from {$diploma->name} to {$masters->name}")
                ->where('qualification.level1_review.evaluation_report.original_name', 'report.pdf'));
    }

    public function test_unauthorized_user_cannot_submit_level1_completion(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $level1 = $this->makeLevel1();
        $other = $this->makeLevel1();
        $type = $this->diplomaType();
        $qualification = $this->makeAssignedQualification($level1, $type);

        $this->actingAs($other)
            ->post(route('admin.verification.qualifications.level1_complete', ['qualification' => $qualification->id]), [
                'qualification_type_id' => $type->id,
                'recommended_for_award' => '0',
                'findings' => 'Attempted completion.',
            ])
            ->assertForbidden();
    }
}
