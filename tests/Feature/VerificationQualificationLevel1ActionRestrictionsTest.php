<?php

namespace Tests\Feature;

use App\Domain\Verification\AssignmentService;
use App\Domain\Verification\QualificationLevel1ReviewService;
use App\Enums\ApplicationStatus;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\Qualification;
use App\Models\User;
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
        return Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test',
            'qualification_holder_name' => 'John Doe',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
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
        $reviews->completeLevel1($qualification, $level1, 'All documents match. Recommend approval.', null);

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
}

