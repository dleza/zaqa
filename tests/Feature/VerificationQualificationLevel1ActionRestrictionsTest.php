<?php

namespace Tests\Feature;

use App\Domain\Verification\AssignmentService;
use App\Domain\Verification\QualificationLevel1ReviewService;
use App\Enums\ApplicationStatus;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\Qualification;
use App\Models\User;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\QualificationTypesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerificationQualificationLevel1ActionRestrictionsTest extends TestCase
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
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'application_number' => 'ZAQA-VER-'.rand(1000, 9999),
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

    private function makeQualification(Application $application): Qualification
    {
        $this->seed([BillingCategoriesSeeder::class, QualificationTypesSeeder::class]);
        $type = \App\Models\QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();

        return Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test',
            'qualification_holder_name' => 'John Doe',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ]);
    }

    public function test_level1_cannot_edit_or_send_back_after_submitting_to_level2(): void
    {
        $application = $this->makeSubmittedApplication();
        $qualification = $this->makeQualification($application);

        $level2 = User::factory()->activated()->create(['applicant_type' => null]);
        $level2->givePermissionTo('verification.assign');
        $level2->givePermissionTo('verification.pool.view');
        $level2->givePermissionTo('dashboard.view');
        $level2->givePermissionTo('verification.level2.review');
        $level2->givePermissionTo('verification.send_back');

        $level1 = User::factory()->activated()->create(['applicant_type' => null]);
        $level1->givePermissionTo('dashboard.view');
        $level1->givePermissionTo('verification.pool.view');
        $level1->givePermissionTo('verification.level1.process');
        $level1->givePermissionTo('verification.send_back');

        /** @var AssignmentService $assignments */
        $assignments = $this->app->make(AssignmentService::class);
        $assignments->assign($qualification, $level2, $level1, 'Please review.');

        /** @var QualificationLevel1ReviewService $reviews */
        $reviews = $this->app->make(QualificationLevel1ReviewService::class);
        $reviews->completeLevel1($qualification, $level1, 'All documents match. Recommend approval.', false, (int) $qualification->qualification_type_id);

        $qualification->refresh();
        $this->assertSame(VerificationState::UnderLevel2Review, $qualification->verification_state);

        $this->actingAs($level1)
            ->get("/admin/verification/qualifications/{$qualification->id}/edit")
            ->assertForbidden();

        $this->actingAs($level1)
            ->post("/admin/verification/qualifications/{$qualification->id}/send-back", [
                'comment' => 'Please amend X.',
            ])
            ->assertSessionHasErrors(['qualification']);

        $qualification->refresh();
        $this->assertSame(VerificationState::UnderLevel2Review, $qualification->verification_state);
    }

    public function test_level1_can_edit_after_returned_from_level2_for_correction(): void
    {
        $application = $this->makeSubmittedApplication();
        $qualification = $this->makeQualification($application);

        $level2 = User::factory()->activated()->create(['applicant_type' => null]);
        $level2->givePermissionTo([
            'verification.assign',
            'verification.pool.view',
            'verification.level2.review',
            'verification.send_back',
        ]);

        $level1 = User::factory()->activated()->create(['applicant_type' => null]);
        $level1->givePermissionTo([
            'dashboard.view',
            'verification.pool.view',
            'verification.level1.process',
        ]);

        app(AssignmentService::class)->assign($qualification, $level2, $level1, 'Please review.');
        app(QualificationLevel1ReviewService::class)->completeLevel1(
            $qualification,
            $level1,
            'Initial review complete.',
            false,
            (int) $qualification->qualification_type_id,
        );

        $qualification->refresh();
        $qualification->forceFill([
            'verification_state' => VerificationState::UnderLevel1Review,
            'assigned_verifier_id' => $level1->id,
            'returned_to_level1_to_user_id' => $level1->id,
            'returned_to_level1_by_user_id' => $level2->id,
            'returned_to_level1_at' => now(),
            'level1_correction_cycle' => 1,
            'level2_review_owner_id' => null,
        ])->save();

        $this->actingAs($level1)
            ->get("/admin/verification/qualifications/{$qualification->id}/edit")
            ->assertOk();
    }
}

