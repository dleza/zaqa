<?php

namespace Tests\Feature;

use App\Domain\Verification\AssignmentService;
use App\Domain\Verification\QualificationAutoAssignmentService;
use App\Domain\Verification\QualificationLevel1ReviewService;
use App\Enums\AssignmentCategoryReviewLevel;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\Qualification;
use App\Models\User;
use App\Models\VerificationAssignmentCategory;
use App\Models\VerificationAssignmentCategoryUser;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\QualificationTypesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class QualificationLevel2AutoAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function diplomaType(): \App\Models\QualificationType
    {
        $this->seed([BillingCategoriesSeeder::class, QualificationTypesSeeder::class]);

        return \App\Models\QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();
    }

    private function makeLevel1(string $name = 'L1'): User
    {
        $u = User::factory()->activated()->create(['applicant_type' => null, 'name' => $name]);
        $u->assignRole('Verification Officer Level 1');
        $u->givePermissionTo('verification.level1.process');

        return $u;
    }

    private function makeLevel2(string $name = 'L2'): User
    {
        $u = User::factory()->activated()->create([
            'applicant_type' => null,
            'name' => $name,
            'email' => strtolower(str_replace(' ', '', $name)).'@example.test',
        ]);
        $u->assignRole('Verification Officer Level 2');

        return $u;
    }

    private function makeApplication(): Application
    {
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        return Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-L2-'.rand(1000, 9999),
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

    private function makeLocalCategory(AwardingInstitution $institution): VerificationAssignmentCategory
    {
        $category = VerificationAssignmentCategory::query()->create([
            'name' => 'Local '.$institution->name,
            'type' => 'local_institution',
            'is_active' => true,
        ]);
        $category->awardingInstitutions()->attach($institution->id);

        return $category;
    }

    private function attachLevel1(VerificationAssignmentCategory $category, User $user, array $overrides = []): void
    {
        VerificationAssignmentCategoryUser::query()->create(array_merge([
            'verification_assignment_category_id' => $category->id,
            'user_id' => $user->id,
            'review_level' => AssignmentCategoryReviewLevel::Level1->value,
            'is_active' => true,
            'is_available' => true,
        ], $overrides));
    }

    private function attachLevel2(VerificationAssignmentCategory $category, User $user, array $overrides = []): void
    {
        VerificationAssignmentCategoryUser::query()->create(array_merge([
            'verification_assignment_category_id' => $category->id,
            'user_id' => $user->id,
            'review_level' => AssignmentCategoryReviewLevel::Level2->value,
            'is_active' => true,
            'is_available' => true,
        ], $overrides));
    }

    public function test_admin_can_attach_level2_officers_to_assignment_category(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->assignRole('Super Admin');

        $zmb = Country::query()->create(['iso_code' => 'ZMB', 'name' => 'Zambia', 'is_active' => true, 'sort_order' => 0]);
        $inst = AwardingInstitution::query()->create(['country_id' => $zmb->id, 'name' => 'Test Uni', 'is_active' => true, 'sort_order' => 0]);
        $category = $this->makeLocalCategory($inst);
        $level2 = $this->makeLevel2('Category L2');

        $this->actingAs($admin)
            ->post(route('admin.verification.assignment_categories.members.store', ['assignmentCategory' => $category->id]), [
                'user_id' => $level2->id,
                'review_level' => 'level2',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('verification_assignment_category_user', [
            'verification_assignment_category_id' => $category->id,
            'user_id' => $level2->id,
            'review_level' => 'level2',
            'is_active' => true,
        ]);
    }

    public function test_migration_preserves_existing_level1_category_assignments_as_level1(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $zmb = Country::query()->create(['iso_code' => 'ZMB', 'name' => 'Zambia', 'is_active' => true, 'sort_order' => 0]);
        $inst = AwardingInstitution::query()->create(['country_id' => $zmb->id, 'name' => 'Legacy Uni', 'is_active' => true, 'sort_order' => 0]);
        $category = $this->makeLocalCategory($inst);
        $level1 = $this->makeLevel1();

        DB::table('verification_assignment_category_user')->insert([
            'verification_assignment_category_id' => $category->id,
            'user_id' => $level1->id,
            'review_level' => 'level1',
            'is_active' => true,
            'is_available' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('verification_assignment_category_user', [
            'verification_assignment_category_id' => $category->id,
            'user_id' => $level1->id,
            'review_level' => 'level1',
        ]);
    }

    public function test_level1_category_assignment_still_works(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $zmb = Country::query()->create(['iso_code' => 'ZMB', 'name' => 'Zambia', 'is_active' => true, 'sort_order' => 0]);
        $inst = AwardingInstitution::query()->create(['country_id' => $zmb->id, 'name' => 'Test Uni', 'is_active' => true, 'sort_order' => 0]);
        $category = $this->makeLocalCategory($inst);
        $level1 = $this->makeLevel1();
        $this->attachLevel1($category, $level1);

        $application = $this->makeApplication();
        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_id' => $inst->id,
            'awarding_institution_name' => $inst->name,
            'qualification_holder_name' => 'Jane Doe',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => 'Diploma',
            'award_date' => '2024-01-10',
            'qualification_type' => 'L6',
            'is_foreign_qualification' => false,
            'verification_state' => VerificationState::AwaitingAssignment,
            'transcript_required' => false,
        ]);

        $result = app(QualificationAutoAssignmentService::class)->autoAssign($qualification);
        $this->assertTrue($result->assigned);
        $qualification->refresh();
        $this->assertSame($level1->id, (int) $qualification->assigned_verifier_id);
    }

    public function test_level1_completion_auto_assigns_category_level2_officer(): void
    {
        Mail::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        $type = $this->diplomaType();

        $zmb = Country::query()->create(['iso_code' => 'ZMB', 'name' => 'Zambia', 'is_active' => true, 'sort_order' => 0]);
        $inst = AwardingInstitution::query()->create(['country_id' => $zmb->id, 'name' => 'Test Uni', 'is_active' => true, 'sort_order' => 0]);
        $category = $this->makeLocalCategory($inst);

        $assigner = $this->makeLevel2('Assigner');
        $assigner->givePermissionTo('verification.assign');
        $categoryL2 = $this->makeLevel2('Category L2');
        $categoryL2->givePermissionTo(['verification.level2.review', 'verification.pool.view', 'dashboard.view']);
        $level1 = $this->makeLevel1();

        $this->attachLevel2($category, $categoryL2);

        $application = $this->makeApplication();
        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_id' => $inst->id,
            'awarding_institution_name' => $inst->name,
            'qualification_holder_name' => 'Jane Doe',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '222222/22/2',
            'title_of_qualification' => 'Diploma',
            'award_date' => '2024-01-10',
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'verification_assignment_category_id' => $category->id,
        ]);

        app(AssignmentService::class)->assign($qualification, $assigner, $level1, 'Please review.');

        app(QualificationLevel1ReviewService::class)->completeLevel1($qualification, $level1, 'Recommend approval.', false, $type->id);

        $qualification->refresh();
        $this->assertSame(VerificationState::UnderLevel2Review, $qualification->verification_state);
        $this->assertSame($categoryL2->id, (int) $qualification->level2_review_owner_id);
        $this->assertSame($category->id, (int) $qualification->verification_assignment_category_id);

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $categoryL2->id,
            'type' => 'verification.qualification_assigned_level2',
        ]);

        Mail::assertQueued(\App\Mail\Verification\QualificationAssignedToLevel2ReviewerMail::class);
    }

    public function test_fair_distribution_across_multiple_level2_officers(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $type = $this->diplomaType();

        $zmb = Country::query()->create(['iso_code' => 'ZMB', 'name' => 'Zambia', 'is_active' => true, 'sort_order' => 0]);
        $inst = AwardingInstitution::query()->create(['country_id' => $zmb->id, 'name' => 'Test Uni', 'is_active' => true, 'sort_order' => 0]);
        $category = $this->makeLocalCategory($inst);

        $assigner = $this->makeLevel2('Assigner');
        $assigner->givePermissionTo('verification.assign');
        $l2a = $this->makeLevel2('L2 A');
        $l2b = $this->makeLevel2('L2 B');
        $level1 = $this->makeLevel1();

        $this->attachLevel2($category, $l2a, ['last_assigned_at' => now()->subDay()]);
        $this->attachLevel2($category, $l2b, ['last_assigned_at' => now()->subDays(2)]);

        for ($i = 0; $i < 2; $i++) {
            Qualification::query()->create([
                'application_id' => $this->makeApplication()->id,
                'awarding_institution_id' => $inst->id,
                'awarding_institution_name' => $inst->name,
                'qualification_holder_name' => 'Existing',
                'country_name_other' => 'Zambia',
                'nrc_passport_number' => '99999'.$i.'/99/9',
                'title_of_qualification' => 'Diploma',
                'award_date' => '2024-01-10',
                'qualification_type' => $type->zqf_level_code,
                'qualification_type_id' => $type->id,
                'is_foreign_qualification' => false,
                'verification_state' => VerificationState::UnderLevel2Review,
                'level2_review_owner_id' => $l2b->id,
                'transcript_required' => false,
            ]);
        }

        $application = $this->makeApplication();
        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_id' => $inst->id,
            'awarding_institution_name' => $inst->name,
            'qualification_holder_name' => 'Jane Doe',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '333333/33/3',
            'title_of_qualification' => 'Diploma',
            'award_date' => '2024-01-10',
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'verification_assignment_category_id' => $category->id,
        ]);

        app(AssignmentService::class)->assign($qualification, $assigner, $level1);
        app(QualificationLevel1ReviewService::class)->completeLevel1($qualification, $level1, 'Recommend approval.', false, $type->id);

        $qualification->refresh();
        $this->assertSame($l2a->id, (int) $qualification->level2_review_owner_id);
    }

    public function test_ineligible_level2_users_are_not_selected(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $type = $this->diplomaType();

        $zmb = Country::query()->create(['iso_code' => 'ZMB', 'name' => 'Zambia', 'is_active' => true, 'sort_order' => 0]);
        $inst = AwardingInstitution::query()->create(['country_id' => $zmb->id, 'name' => 'Test Uni', 'is_active' => true, 'sort_order' => 0]);
        $category = $this->makeLocalCategory($inst);

        $assigner = $this->makeLevel2('Assigner');
        $assigner->givePermissionTo('verification.assign');
        $inactiveL2 = $this->makeLevel2('Inactive');
        $inactiveL2->forceFill(['is_active' => false])->save();
        $unavailableL2 = $this->makeLevel2('Unavailable');
        $eligibleL2 = $this->makeLevel2('Eligible');
        $level1 = $this->makeLevel1();

        $this->attachLevel2($category, $inactiveL2);
        $this->attachLevel2($category, $unavailableL2, ['is_available' => false]);
        $this->attachLevel2($category, $eligibleL2);

        $application = $this->makeApplication();
        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_id' => $inst->id,
            'awarding_institution_name' => $inst->name,
            'qualification_holder_name' => 'Jane Doe',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '444444/44/4',
            'title_of_qualification' => 'Diploma',
            'award_date' => '2024-01-10',
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'verification_assignment_category_id' => $category->id,
        ]);

        app(AssignmentService::class)->assign($qualification, $assigner, $level1);
        app(QualificationLevel1ReviewService::class)->completeLevel1($qualification, $level1, 'Recommend approval.', false, $type->id);

        $qualification->refresh();
        $this->assertSame($eligibleL2->id, (int) $qualification->level2_review_owner_id);
    }

    public function test_no_level2_officers_falls_back_to_level2_pool(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $type = $this->diplomaType();

        $zmb = Country::query()->create(['iso_code' => 'ZMB', 'name' => 'Zambia', 'is_active' => true, 'sort_order' => 0]);
        $inst = AwardingInstitution::query()->create(['country_id' => $zmb->id, 'name' => 'Test Uni', 'is_active' => true, 'sort_order' => 0]);
        $category = $this->makeLocalCategory($inst);

        $assigner = $this->makeLevel2('Assigner');
        $assigner->givePermissionTo('verification.assign');
        $level1 = $this->makeLevel1();

        $application = $this->makeApplication();
        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_id' => $inst->id,
            'awarding_institution_name' => $inst->name,
            'qualification_holder_name' => 'Jane Doe',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '555555/55/5',
            'title_of_qualification' => 'Diploma',
            'award_date' => '2024-01-10',
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'verification_assignment_category_id' => $category->id,
        ]);

        app(AssignmentService::class)->assign($qualification, $assigner, $level1);
        app(QualificationLevel1ReviewService::class)->completeLevel1($qualification, $level1, 'Recommend approval.', false, $type->id);

        $qualification->refresh();
        $this->assertSame(VerificationState::UnderLevel2Review, $qualification->verification_state);
        $this->assertNull($qualification->level2_review_owner_id);

        $this->assertDatabaseHas('audit_logs', [
            'action_name' => 'level2_auto_assignment_failed',
            'entity_type' => Qualification::class,
            'entity_id' => $qualification->id,
        ]);
    }

    public function test_level2_assigned_to_me_includes_category_auto_assigned_qualification(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $type = $this->diplomaType();

        $zmb = Country::query()->create(['iso_code' => 'ZMB', 'name' => 'Zambia', 'is_active' => true, 'sort_order' => 0]);
        $inst = AwardingInstitution::query()->create(['country_id' => $zmb->id, 'name' => 'Test Uni', 'is_active' => true, 'sort_order' => 0]);
        $category = $this->makeLocalCategory($inst);

        $assigner = $this->makeLevel2('Assigner');
        $assigner->givePermissionTo('verification.assign');
        $categoryL2 = $this->makeLevel2('Category L2');
        $categoryL2->givePermissionTo(['verification.level2.review', 'verification.pool.view', 'dashboard.view']);
        $level1 = $this->makeLevel1();
        $this->attachLevel2($category, $categoryL2);

        $application = $this->makeApplication();
        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_id' => $inst->id,
            'awarding_institution_name' => $inst->name,
            'qualification_holder_name' => 'Jane Doe',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '666666/66/6',
            'title_of_qualification' => 'Diploma',
            'award_date' => '2024-01-10',
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'verification_assignment_category_id' => $category->id,
        ]);

        app(AssignmentService::class)->assign($qualification, $assigner, $level1);
        app(QualificationLevel1ReviewService::class)->completeLevel1($qualification, $level1, 'Recommend approval.', false, $type->id);

        $this->actingAs($categoryL2)
            ->get(route('admin.verification.assigned_to_me'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('qualifications.data', 1)
                ->where('qualifications.data.0.id', $qualification->id));
    }
}
