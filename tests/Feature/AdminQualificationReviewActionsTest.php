<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\VerificationState;
use App\Jobs\Verification\ProcessQualificationAutoVerificationJob;
use App\Mail\Verification\QualificationAssignedToVerifierMail;
use App\Models\Application;
use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\Payment;
use App\Models\Qualification;
use App\Models\QualificationAssignment;
use App\Models\QualificationType;
use App\Models\User;
use App\Models\VerificationAssignmentCategory;
use App\Models\VerificationAssignmentCategoryUser;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\FeeStructuresSeeder;
use Database\Seeders\QualificationTypesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminQualificationReviewActionsTest extends TestCase
{
    use RefreshDatabase;

    protected QualificationType $qualificationType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BillingCategoriesSeeder::class);
        $this->seed(QualificationTypesSeeder::class);
        $this->seed(FeeStructuresSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->qualificationType = QualificationType::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->firstOrFail();
    }

    public function test_level2_can_queue_auto_verification_recheck_and_audit_it(): void
    {
        Bus::fake();

        $level2 = $this->makeLevel2('Level 2 Officer');
        [$country, $institution] = $this->makeInstitution();
        $application = $this->makePaidSubmittedApplication();
        $qualification = $this->makeQualification($application, [
            'country_id' => $country->id,
            'awarding_institution_id' => $institution->id,
            'awarding_institution_name' => $institution->name,
            'student_number' => 'STU-001',
            'verification_state' => VerificationState::AwaitingAssignment,
        ]);

        $this->actingAs($level2)
            ->post(route('admin.verification.qualifications.recheck_auto_verification', $qualification))
            ->assertRedirect()
            ->assertSessionHas('success', 'Auto-verification recheck has been queued.');

        Bus::assertDispatched(ProcessQualificationAutoVerificationJob::class, function (ProcessQualificationAutoVerificationJob $job) use ($qualification) {
            return $job->qualificationId === $qualification->id
                && $job->manualRecheck === true
                && $job->resumeState === null
                && $job->resumeAssigneeId === null;
        });

        $this->assertDatabaseHas('application_comments', [
            'application_id' => $application->id,
            'qualification_id' => $qualification->id,
            'type' => 'verification_note',
            'body' => 'Auto-verification recheck queued by '.$level2->name.'.',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'verification.auto_verification_recheck_queued',
            'entity_type' => Qualification::class,
            'entity_id' => $qualification->id,
            'actor_user_id' => $level2->id,
        ]);
    }

    public function test_super_admin_can_queue_auto_verification_recheck(): void
    {
        Bus::fake();

        $superAdmin = User::factory()->activated()->create(['applicant_type' => null]);
        $superAdmin->assignRole('Super Admin');

        [$country, $institution] = $this->makeInstitution();
        $application = $this->makePaidSubmittedApplication();
        $qualification = $this->makeQualification($application, [
            'country_id' => $country->id,
            'awarding_institution_id' => $institution->id,
            'awarding_institution_name' => $institution->name,
            'student_number' => 'STU-002',
            'verification_state' => VerificationState::AwaitingAssignment,
        ]);

        $this->actingAs($superAdmin)
            ->post(route('admin.verification.qualifications.recheck_auto_verification', $qualification))
            ->assertRedirect()
            ->assertSessionHas('success', 'Auto-verification recheck has been queued.');

        Bus::assertDispatched(ProcessQualificationAutoVerificationJob::class);
    }

    public function test_level1_cannot_queue_auto_verification_recheck(): void
    {
        $level1 = $this->makeLevel1('Level 1 Officer');
        $application = $this->makePaidSubmittedApplication();
        $qualification = $this->makeQualification($application, [
            'assigned_verifier_id' => $level1->id,
            'assigned_at' => now(),
            'verification_state' => VerificationState::AssignedToLevel1,
        ]);

        $this->actingAs($level1)
            ->post(route('admin.verification.qualifications.recheck_auto_verification', $qualification))
            ->assertForbidden();
    }

    public function test_certificate_issued_qualification_cannot_be_rechecked(): void
    {
        Bus::fake();

        $level2 = $this->makeLevel2();
        $application = $this->makePaidSubmittedApplication();
        $qualification = $this->makeQualification($application, [
            'verification_state' => VerificationState::CertificateIssued,
        ]);

        $this->actingAs($level2)
            ->post(route('admin.verification.qualifications.recheck_auto_verification', $qualification))
            ->assertRedirect()
            ->assertSessionHas('error', 'This qualification already has a certificate.');

        Bus::assertNotDispatched(ProcessQualificationAutoVerificationJob::class);
    }

    public function test_level2_can_retry_level1_auto_assignment_and_history_notification_are_created(): void
    {
        Mail::fake();

        $level2 = $this->makeLevel2();
        [$country, $institution] = $this->makeInstitution();
        $application = $this->makePaidSubmittedApplication();
        $qualification = $this->makeQualification($application, [
            'country_id' => $country->id,
            'awarding_institution_id' => $institution->id,
            'awarding_institution_name' => $institution->name,
            'verification_state' => VerificationState::AwaitingAssignment,
            'student_number' => 'STU-100',
        ]);

        $category = VerificationAssignmentCategory::query()->create([
            'name' => 'Local Pool',
            'type' => 'local_institution',
            'is_active' => true,
        ]);
        $category->awardingInstitutions()->attach($institution->id);

        $level1 = $this->makeLevel1('Officer A');
        VerificationAssignmentCategoryUser::query()->create([
            'verification_assignment_category_id' => $category->id,
            'user_id' => $level1->id,
            'is_active' => true,
            'is_available' => true,
        ]);

        $this->actingAs($level2)
            ->post(route('admin.verification.qualifications.auto_assign_level1', $qualification))
            ->assertRedirect()
            ->assertSessionHas('success', 'Auto-assignment retried by '.$level2->name.'; assigned to '.$level1->name.'.');

        $qualification->refresh();
        $this->assertSame($level1->id, (int) $qualification->assigned_verifier_id);
        $this->assertSame(VerificationState::AssignedToLevel1, $qualification->verification_state);
        $this->assertSame('auto', (string) $qualification->assignment_source);
        $this->assertSame($category->id, (int) $qualification->verification_assignment_category_id);
        $this->assertNotNull($qualification->auto_assigned_at);
        $this->assertSame(1, QualificationAssignment::query()->where('qualification_id', $qualification->id)->count());

        $this->assertDatabaseHas('application_comments', [
            'application_id' => $application->id,
            'qualification_id' => $qualification->id,
            'type' => 'assignment_note',
            'body' => 'Auto-assignment retried by '.$level2->name.'; assigned to '.$level1->name.'.',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'verification.auto_assignment_retry',
            'entity_type' => Qualification::class,
            'entity_id' => $qualification->id,
            'actor_user_id' => $level2->id,
        ]);
        Mail::assertQueued(QualificationAssignedToVerifierMail::class);
    }

    public function test_auto_assign_retry_records_failure_when_no_category_exists(): void
    {
        $level2 = $this->makeLevel2();
        [$country, $institution] = $this->makeInstitution();
        $application = $this->makePaidSubmittedApplication();
        $qualification = $this->makeQualification($application, [
            'country_id' => $country->id,
            'awarding_institution_id' => $institution->id,
            'awarding_institution_name' => $institution->name,
            'verification_state' => VerificationState::AwaitingAssignment,
        ]);

        $this->actingAs($level2)
            ->post(route('admin.verification.qualifications.auto_assign_level1', $qualification))
            ->assertRedirect()
            ->assertSessionHas('error', 'Auto-assignment retried by '.$level2->name.'; failed: No active assignment category found');

        $qualification->refresh();
        $this->assertNull($qualification->assigned_verifier_id);
        $this->assertSame('No active assignment category found', (string) $qualification->assignment_failure_reason);
    }

    public function test_auto_assign_retry_records_failure_when_no_eligible_officer_exists(): void
    {
        $level2 = $this->makeLevel2();
        [$country, $institution] = $this->makeInstitution();
        $application = $this->makePaidSubmittedApplication();
        $qualification = $this->makeQualification($application, [
            'country_id' => $country->id,
            'awarding_institution_id' => $institution->id,
            'awarding_institution_name' => $institution->name,
            'verification_state' => VerificationState::AwaitingAssignment,
        ]);

        $category = VerificationAssignmentCategory::query()->create([
            'name' => 'Empty Pool',
            'type' => 'local_institution',
            'is_active' => true,
        ]);
        $category->awardingInstitutions()->attach($institution->id);

        $this->actingAs($level2)
            ->post(route('admin.verification.qualifications.auto_assign_level1', $qualification))
            ->assertRedirect()
            ->assertSessionHas('error', 'Auto-assignment retried by '.$level2->name.'; failed: No available Level 1 officer for category.');

        $qualification->refresh();
        $this->assertSame('No available Level 1 officer for category.', (string) $qualification->assignment_failure_reason);
    }

    public function test_auto_assign_retry_fails_safe_when_category_mapping_is_ambiguous(): void
    {
        $level2 = $this->makeLevel2();
        [$country] = $this->makeInstitution();
        $application = $this->makePaidSubmittedApplication();
        $qualification = $this->makeQualification($application, [
            'country_id' => $country->id,
            'is_foreign_qualification' => true,
            'verification_state' => VerificationState::AwaitingAssignment,
            'awarding_institution_id' => null,
            'awarding_institution_name' => 'Foreign Institute',
        ]);

        $categoryA = VerificationAssignmentCategory::query()->create([
            'name' => 'Pool A',
            'type' => 'foreign_country',
            'is_active' => true,
        ]);
        $categoryA->countries()->attach($country->id);

        $categoryB = VerificationAssignmentCategory::query()->create([
            'name' => 'Pool B',
            'type' => 'foreign_country',
            'is_active' => true,
        ]);
        $categoryB->countries()->attach($country->id);

        $this->actingAs($level2)
            ->post(route('admin.verification.qualifications.auto_assign_level1', $qualification))
            ->assertRedirect()
            ->assertSessionHas('error', 'Auto-assignment retried by '.$level2->name.'; failed: Ambiguous assignment category mapping.');

        $qualification->refresh();
        $this->assertSame('Ambiguous assignment category mapping.', (string) $qualification->assignment_failure_reason);
    }

    public function test_auto_assign_retry_does_not_silently_reassign_active_work(): void
    {
        $level2 = $this->makeLevel2();
        $level1 = $this->makeLevel1('Already Assigned');
        $application = $this->makePaidSubmittedApplication();
        $qualification = $this->makeQualification($application, [
            'assigned_verifier_id' => $level1->id,
            'assigned_at' => now(),
            'verification_state' => VerificationState::AssignedToLevel1,
        ]);

        $this->actingAs($level2)
            ->post(route('admin.verification.qualifications.auto_assign_level1', $qualification))
            ->assertRedirect()
            ->assertSessionHas('error', 'This qualification is already assigned to '.$level1->name.'. Use reassignment if you need to change the officer.');

        $qualification->refresh();
        $this->assertSame($level1->id, (int) $qualification->assigned_verifier_id);
        $this->assertSame(0, QualificationAssignment::query()->where('qualification_id', $qualification->id)->count());
    }

    public function test_review_page_exposes_filtered_learner_records_link_when_authorized(): void
    {
        $level2 = $this->makeLevel2();
        $level2->givePermissionTo('learner_records.view');

        [$country, $institution] = $this->makeInstitution();
        $application = $this->makePaidSubmittedApplication();
        $qualification = $this->makeQualification($application, [
            'country_id' => $country->id,
            'awarding_institution_id' => $institution->id,
            'awarding_institution_name' => $institution->name,
        ]);

        $this->actingAs($level2)
            ->get(route('admin.verification.qualifications.show', $qualification))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('qualification.learner_records_url', route('admin.learner_records.index', ['awarding_institution_id' => $institution->id]))
                ->where('can.view_learner_records', true)
            );
    }

    public function test_review_page_disables_learner_records_link_when_no_linked_institution_exists(): void
    {
        $level2 = $this->makeLevel2();
        $level2->givePermissionTo('learner_records.view');

        $application = $this->makePaidSubmittedApplication();
        $qualification = $this->makeQualification($application, [
            'awarding_institution_id' => null,
            'awarding_institution_name' => 'Other / Manual',
        ]);

        $this->actingAs($level2)
            ->get(route('admin.verification.qualifications.show', $qualification))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('qualification.learner_records_url', null)
                ->where('qualification.learner_records_disabled_reason', 'No linked awarding institution for this qualification.')
            );
    }

    private function makeLevel1(string $name = 'Level 1'): User
    {
        $user = User::factory()->activated()->create([
            'applicant_type' => null,
            'name' => $name,
            'email' => Str::slug($name).'-level1@example.test',
        ]);
        $user->assignRole('Verification Officer Level 1');

        return $user;
    }

    private function makeLevel2(string $name = 'Level 2'): User
    {
        $user = User::factory()->activated()->create([
            'applicant_type' => null,
            'name' => $name,
            'email' => Str::slug($name).'-level2@example.test',
        ]);
        $user->assignRole('Verification Officer Level 2');

        return $user;
    }

    /**
     * @return array{0: Country, 1: AwardingInstitution}
     */
    private function makeInstitution(): array
    {
        $country = Country::query()->create([
            'iso_code' => 'ZMB',
            'name' => 'Zambia',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $institution = AwardingInstitution::query()->create([
            'country_id' => $country->id,
            'name' => 'Test University '.Str::random(5),
            'consent_form_path' => null,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        return [$country, $institution];
    }

    private function makePaidSubmittedApplication(): Application
    {
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        return Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-RET-'.rand(1000, 9999),
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeQualification(Application $application, array $overrides = []): Qualification
    {
        $qualification = Qualification::query()->create(array_merge([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test Institution',
            'qualification_holder_name' => 'Jane Doe',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => 'Diploma in Testing',
            'award_date' => '2024-01-10',
            'qualification_type' => $this->qualificationType->zqf_level_code,
            'qualification_type_id' => $this->qualificationType->id,
            'is_foreign_qualification' => false,
            'verification_state' => VerificationState::AwaitingAssignment,
            'transcript_required' => false,
        ], $overrides));

        $required = app(\App\Domain\Fees\QualificationFeeResolver::class)->totalVerificationFeesCents($application->fresh());

        Payment::query()->create([
            'application_id' => $application->id,
            'method' => PaymentMethod::Card,
            'status' => PaymentStatus::Confirmed,
            'currency' => 'ZMW',
            'amount_cents' => $required,
            'provider' => 'test',
            'provider_reference' => 'PAY-'.Str::random(8),
            'confirmed_at' => now(),
            'last_status_at' => now(),
        ]);

        return $qualification;
    }
}
