<?php

namespace Tests\Feature;

use App\Domain\Verification\QualificationAutoAssignmentService;
use App\Domain\Verification\AutoVerifiedQualificationReviewService;
use App\Enums\VerificationState;
use App\Jobs\Verification\ProcessQualificationAutoVerificationJob;
use App\Models\Application;
use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\Qualification;
use App\Models\User;
use App\Models\VerificationAssignmentCategory;
use App\Models\VerificationAssignmentCategoryUser;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class QualificationAutoAssignmentEngineTest extends TestCase
{
    use RefreshDatabase;

    private function makeLevel1(string $name): User
    {
        $u = User::factory()->activated()->create([
            'applicant_type' => null,
            'name' => $name,
        ]);
        $u->assignRole('Verification Officer Level 1');
        return $u;
    }

    private function makeLevel2(string $name = 'L2'): User
    {
        $u = User::factory()->activated()->create([
            'applicant_type' => null,
            'name' => $name,
        ]);
        $u->assignRole('Verification Officer Level 2');
        return $u;
    }

    private function makeApplication(User $applicant): Application
    {
        return Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-AA-'.rand(1000, 9999),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => 'submitted',
            'verification_state' => VerificationState::AwaitingAutoVerification,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
            'service_deadline_at' => now()->addDays(14),
            'paid_at' => now(),
        ]);
    }

    public function test_foreign_category_auto_assigns_to_lowest_workload_then_least_recent(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $usa = Country::query()->create(['iso_code' => 'USA', 'name' => 'United States', 'is_active' => true, 'sort_order' => 0]);

        $category = VerificationAssignmentCategory::query()->create([
            'name' => 'USA',
            'type' => 'foreign_country',
            'is_active' => true,
        ]);
        $category->countries()->attach($usa->id);

        $level1A = $this->makeLevel1('Officer A');
        $level1B = $this->makeLevel1('Officer B');

        VerificationAssignmentCategoryUser::query()->create([
            'verification_assignment_category_id' => $category->id,
            'user_id' => $level1A->id,
            'is_active' => true,
            'is_available' => true,
            'last_assigned_at' => now()->subDay(),
        ]);
        VerificationAssignmentCategoryUser::query()->create([
            'verification_assignment_category_id' => $category->id,
            'user_id' => $level1B->id,
            'is_active' => true,
            'is_available' => true,
            'last_assigned_at' => now()->subDays(2),
        ]);

        // Give Officer B a heavier workload so Officer A should be picked.
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $application = $this->makeApplication($applicant);

        for ($i = 0; $i < 3; $i++) {
            Qualification::query()->create([
                'application_id' => $application->id,
                'awarding_institution_name' => 'X',
                'qualification_holder_name' => 'John Doe',
                'country_id' => $usa->id,
                'nrc_passport_number' => '111111/11/1',
                'title_of_qualification' => 'Diploma',
                'award_date' => '2024-01-10',
                'qualification_type' => 'L6',
                'is_foreign_qualification' => true,
                'verification_state' => VerificationState::AssignedToLevel1,
                'assigned_verifier_id' => $level1B->id,
                'assigned_at' => now(),
                'transcript_required' => false,
            ]);
        }

        $q = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'X',
            'qualification_holder_name' => 'Jane Doe',
            'country_id' => $usa->id,
            'nrc_passport_number' => '222222/22/2',
            'title_of_qualification' => 'Diploma',
            'award_date' => '2024-01-10',
            'qualification_type' => 'L6',
            'is_foreign_qualification' => true,
            'verification_state' => VerificationState::AwaitingAssignment,
            'assigned_verifier_id' => null,
            'assigned_at' => null,
            'transcript_required' => false,
        ]);

        $res = app(QualificationAutoAssignmentService::class)->autoAssign($q, actor: null, reason: 'test');
        $this->assertTrue($res->assigned);

        $q->refresh();
        $this->assertSame($level1A->id, (int) $q->assigned_verifier_id);
        $this->assertSame(VerificationState::AssignedToLevel1, $q->verification_state);
        $this->assertSame('auto', (string) $q->assignment_source);
        $this->assertSame($category->id, (int) $q->verification_assignment_category_id);
        $this->assertNotNull($q->auto_assigned_at);
    }

    public function test_unavailable_membership_is_skipped(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $usa = Country::query()->create(['iso_code' => 'USA', 'name' => 'United States', 'is_active' => true, 'sort_order' => 0]);
        $category = VerificationAssignmentCategory::query()->create([
            'name' => 'USA',
            'type' => 'foreign_country',
            'is_active' => true,
        ]);
        $category->countries()->attach($usa->id);

        $level1A = $this->makeLevel1('Officer A');
        $level1B = $this->makeLevel1('Officer B');

        VerificationAssignmentCategoryUser::query()->create([
            'verification_assignment_category_id' => $category->id,
            'user_id' => $level1A->id,
            'is_active' => true,
            'is_available' => false,
            'unavailable_reason' => 'Leave',
            'unavailable_until' => now()->addDay(),
        ]);
        VerificationAssignmentCategoryUser::query()->create([
            'verification_assignment_category_id' => $category->id,
            'user_id' => $level1B->id,
            'is_active' => true,
            'is_available' => true,
        ]);

        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $application = $this->makeApplication($applicant);

        $q = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'X',
            'qualification_holder_name' => 'Jane Doe',
            'country_id' => $usa->id,
            'nrc_passport_number' => '222222/22/2',
            'title_of_qualification' => 'Diploma',
            'award_date' => '2024-01-10',
            'qualification_type' => 'L6',
            'is_foreign_qualification' => true,
            'verification_state' => VerificationState::AwaitingAssignment,
            'transcript_required' => false,
        ]);

        $res = app(QualificationAutoAssignmentService::class)->autoAssign($q);
        $this->assertTrue($res->assigned);
        $q->refresh();
        $this->assertSame($level1B->id, (int) $q->assigned_verifier_id);
    }

    public function test_missing_category_leaves_unassigned_with_failure_reason(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $usa = Country::query()->create(['iso_code' => 'USA', 'name' => 'United States', 'is_active' => true, 'sort_order' => 0]);

        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $application = $this->makeApplication($applicant);

        $q = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'X',
            'qualification_holder_name' => 'Jane Doe',
            'country_id' => $usa->id,
            'nrc_passport_number' => '222222/22/2',
            'title_of_qualification' => 'Diploma',
            'award_date' => '2024-01-10',
            'qualification_type' => 'L6',
            'is_foreign_qualification' => true,
            'verification_state' => VerificationState::AwaitingAssignment,
            'transcript_required' => false,
        ]);

        $res = app(QualificationAutoAssignmentService::class)->autoAssign($q);
        $this->assertFalse($res->assigned);

        $q->refresh();
        $this->assertNull($q->assigned_verifier_id);
        $this->assertSame(VerificationState::AwaitingAssignment, $q->verification_state);
        $this->assertNotNull($q->assignment_failure_reason);
    }

    public function test_auto_verification_fallback_triggers_auto_assignment(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        config([
            'auto_verification.enabled' => true,
            'auto_verification.threshold' => 70,
            'auto_verification.auto_issue_enabled' => false,
        ]);

        $zmb = Country::query()->create(['iso_code' => 'ZMB', 'name' => 'Zambia', 'is_active' => true, 'sort_order' => 0]);
        $inst = AwardingInstitution::query()->create(['country_id' => $zmb->id, 'name' => 'Local Uni', 'is_active' => true, 'sort_order' => 0]);

        $category = VerificationAssignmentCategory::query()->create([
            'name' => $inst->name,
            'type' => 'local_institution',
            'is_active' => true,
        ]);
        $category->awardingInstitutions()->attach($inst->id);

        $level1 = $this->makeLevel1('Officer A');
        VerificationAssignmentCategoryUser::query()->create([
            'verification_assignment_category_id' => $category->id,
            'user_id' => $level1->id,
            'is_active' => true,
            'is_available' => true,
        ]);

        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $application = $this->makeApplication($applicant);

        $q = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_id' => $inst->id,
            'awarding_institution_name' => $inst->name,
            'qualification_holder_name' => 'Jane Doe',
            'country_id' => $zmb->id,
            'nrc_passport_number' => '222222/22/2',
            'title_of_qualification' => 'Diploma',
            'award_date' => '2024-01-10',
            'qualification_type' => 'L6',
            'is_foreign_qualification' => false,
            'verification_state' => VerificationState::AwaitingAutoVerification,
            'transcript_required' => false,
        ]);

        // No learner records exist => match fails => should fall back and auto-assign.
        ProcessQualificationAutoVerificationJob::dispatchSync((int) $q->id);

        $q->refresh();
        $this->assertSame(VerificationState::AssignedToLevel1, $q->verification_state);
        $this->assertSame($level1->id, (int) $q->assigned_verifier_id);
        $this->assertSame('auto', (string) $q->assignment_source);
    }

    public function test_send_to_manual_review_from_level2_triggers_auto_assignment(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $zmb = Country::query()->create(['iso_code' => 'ZMB', 'name' => 'Zambia', 'is_active' => true, 'sort_order' => 0]);
        $inst = AwardingInstitution::query()->create(['country_id' => $zmb->id, 'name' => 'Local Uni', 'is_active' => true, 'sort_order' => 0]);

        $category = VerificationAssignmentCategory::query()->create([
            'name' => $inst->name,
            'type' => 'local_institution',
            'is_active' => true,
        ]);
        $category->awardingInstitutions()->attach($inst->id);

        $level1 = $this->makeLevel1('Officer A');
        VerificationAssignmentCategoryUser::query()->create([
            'verification_assignment_category_id' => $category->id,
            'user_id' => $level1->id,
            'is_active' => true,
            'is_available' => true,
        ]);

        $level2 = $this->makeLevel2();
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $application = $this->makeApplication($applicant);

        $q = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_id' => $inst->id,
            'awarding_institution_name' => $inst->name,
            'qualification_holder_name' => 'Jane Doe',
            'country_id' => $zmb->id,
            'nrc_passport_number' => '222222/22/2',
            'title_of_qualification' => 'Diploma',
            'award_date' => '2024-01-10',
            'qualification_type' => 'L6',
            'is_foreign_qualification' => false,
            'verification_state' => VerificationState::AutoVerifiedPendingLevel2,
            'transcript_required' => false,
        ]);

        // Lock is required for this action in current workflow.
        $q->forceFill(['level2_review_locked_by' => $level2->id, 'level2_review_locked_at' => now()])->save();

        app(AutoVerifiedQualificationReviewService::class)->sendToManualReview($q, $level2);

        $q->refresh();
        $this->assertSame(VerificationState::AssignedToLevel1, $q->verification_state);
        $this->assertSame($level1->id, (int) $q->assigned_verifier_id);
    }
}
