<?php

namespace Tests\Feature;

use App\Domain\Verification\AssignmentService;
use App\Domain\Verification\QualificationLevel1ReviewService;
use App\Enums\ApplicationStatus;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\Qualification;
use App\Models\QualificationType;
use App\Models\User;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\QualificationTypesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssignedToMeQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed([BillingCategoriesSeeder::class, QualificationTypesSeeder::class]);
    }

    private function makeLevel1Officer(): User
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->assignRole('Verification Officer Level 1');

        return $user;
    }

    private function makeLevel2Officer(): User
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->assignRole('Verification Officer Level 2');
        $user->givePermissionTo(['verification.pool.view', 'dashboard.view']);

        return $user;
    }

    private function makeSubmittedApplication(array $overrides = []): Application
    {
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        return Application::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-ATM-'.Str::upper(Str::random(6)),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Submitted,
            'verification_state' => VerificationState::AssignedToLevel1,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
        ], $overrides));
    }

    private function makeQualification(Application $app, array $overrides = []): Qualification
    {
        $type = QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();

        return Qualification::query()->create(array_merge([
            'application_id' => $app->id,
            'verification_reference_number' => 'ZAQA-ATM-'.Str::upper(Str::random(8)),
            'awarding_institution_name' => 'Test Institution',
            'qualification_holder_name' => 'Holder',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => Str::random(6).'/11/1',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'verification_state' => VerificationState::AssignedToLevel1,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ], $overrides));
    }

    /** @return array<int, int> */
    private function assignedToMeIds(User $user): array
    {
        $response = $this->actingAs($user)->get(route('admin.verification.assigned_to_me'));
        $response->assertOk();
        $page = $response->viewData('page');
        $props = json_decode(json_encode($page), true)['props'];

        return collect($props['qualifications']['data'] ?? [])->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_level1_sees_assigned_to_level1_qualification(): void
    {
        $l1 = $this->makeLevel1Officer();
        $qualification = $this->makeQualification($this->makeSubmittedApplication(), [
            'assigned_verifier_id' => $l1->id,
            'verification_state' => VerificationState::AssignedToLevel1,
        ]);

        $this->assertContains($qualification->id, $this->assignedToMeIds($l1));
    }

    public function test_level1_sees_under_level1_review_qualification(): void
    {
        $l1 = $this->makeLevel1Officer();
        $qualification = $this->makeQualification($this->makeSubmittedApplication(), [
            'assigned_verifier_id' => $l1->id,
            'verification_state' => VerificationState::UnderLevel1Review,
        ]);

        $this->assertContains($qualification->id, $this->assignedToMeIds($l1));
    }

    public function test_level1_sees_qualification_returned_by_level2_for_correction(): void
    {
        $l1 = $this->makeLevel1Officer();
        $l2 = $this->makeLevel2Officer();
        $qualification = $this->makeQualification($this->makeSubmittedApplication(), [
            'assigned_verifier_id' => $l1->id,
            'verification_state' => VerificationState::UnderLevel1Review,
            'returned_to_level1_to_user_id' => $l1->id,
            'returned_to_level1_by_user_id' => $l2->id,
            'returned_to_level1_at' => now()->subDay(),
            'level1_correction_cycle' => 1,
        ]);

        $this->assertContains($qualification->id, $this->assignedToMeIds($l1));
    }

    public function test_level1_does_not_see_qualification_after_submitting_to_level2(): void
    {
        $l1 = $this->makeLevel1Officer();
        $l2 = $this->makeLevel2Officer();
        $l2->givePermissionTo('verification.assign');

        $qualification = $this->makeQualification($this->makeSubmittedApplication());
        app(AssignmentService::class)->assign($qualification, $l2, $l1, 'Please review.');
        app(QualificationLevel1ReviewService::class)->completeLevel1(
            $qualification,
            $l1,
            'Recommend approval.',
            false,
            (int) $qualification->qualification_type_id,
        );

        $qualification->refresh();
        $this->assertSame(VerificationState::UnderLevel2Review, $qualification->verification_state);

        $this->assertNotContains($qualification->id, $this->assignedToMeIds($l1));
    }

    public function test_level1_does_not_see_qualification_assigned_to_another_officer(): void
    {
        $l1 = $this->makeLevel1Officer();
        $otherL1 = $this->makeLevel1Officer();
        $qualification = $this->makeQualification($this->makeSubmittedApplication(), [
            'assigned_verifier_id' => $otherL1->id,
            'verification_state' => VerificationState::AssignedToLevel1,
        ]);

        $this->assertNotContains($qualification->id, $this->assignedToMeIds($l1));
    }

    public function test_level1_does_not_see_qualification_returned_to_applicant(): void
    {
        $l1 = $this->makeLevel1Officer();
        $qualification = $this->makeQualification($this->makeSubmittedApplication(), [
            'assigned_verifier_id' => null,
            'verification_state' => VerificationState::ReturnedToApplicant,
            'send_back_by_user_id' => $l1->id,
        ]);

        $this->assertNotContains($qualification->id, $this->assignedToMeIds($l1));
    }

    public function test_level1_does_not_see_terminal_qualification_states(): void
    {
        $l1 = $this->makeLevel1Officer();
        $terminalStates = [
            VerificationState::ApprovedForCertificate,
            VerificationState::Rejected,
            VerificationState::CertificateIssued,
            VerificationState::Closed,
        ];

        foreach ($terminalStates as $state) {
            $qualification = $this->makeQualification($this->makeSubmittedApplication(), [
                'assigned_verifier_id' => $l1->id,
                'verification_state' => $state,
                'title_of_qualification' => 'Terminal '.$state->value,
            ]);

            $this->assertNotContains(
                $qualification->id,
                $this->assignedToMeIds($l1),
                'Unexpected terminal qualification in assigned-to-me: '.$state->value,
            );
        }
    }

    public function test_level2_sees_under_level2_review_qualification_owned_by_them(): void
    {
        $l2 = $this->makeLevel2Officer();
        $qualification = $this->makeQualification($this->makeSubmittedApplication(), [
            'verification_state' => VerificationState::UnderLevel2Review,
            'level2_review_owner_id' => $l2->id,
        ]);

        $this->assertContains($qualification->id, $this->assignedToMeIds($l2));
    }

    public function test_level2_sees_auto_verified_qualification_locked_by_them(): void
    {
        $l2 = $this->makeLevel2Officer();
        $qualification = $this->makeQualification($this->makeSubmittedApplication(), [
            'verification_state' => VerificationState::AutoVerifiedPendingLevel2,
            'level2_review_locked_by' => $l2->id,
            'level2_review_locked_at' => now(),
        ]);

        $this->assertContains($qualification->id, $this->assignedToMeIds($l2));
    }

    public function test_level2_does_not_see_under_level2_review_without_owner(): void
    {
        $l2 = $this->makeLevel2Officer();
        $qualification = $this->makeQualification($this->makeSubmittedApplication(), [
            'verification_state' => VerificationState::UnderLevel2Review,
            'level2_review_owner_id' => null,
        ]);

        $this->assertNotContains($qualification->id, $this->assignedToMeIds($l2));
    }

    public function test_level2_does_not_see_qualification_owned_by_another_officer(): void
    {
        $l2 = $this->makeLevel2Officer();
        $otherL2 = $this->makeLevel2Officer();
        $qualification = $this->makeQualification($this->makeSubmittedApplication(), [
            'verification_state' => VerificationState::UnderLevel2Review,
            'level2_review_owner_id' => $otherL2->id,
        ]);

        $this->assertNotContains($qualification->id, $this->assignedToMeIds($l2));
    }

    public function test_level2_does_not_see_qualification_sent_back_to_level1(): void
    {
        $l1 = $this->makeLevel1Officer();
        $l2 = $this->makeLevel2Officer();
        $qualification = $this->makeQualification($this->makeSubmittedApplication(), [
            'assigned_verifier_id' => $l1->id,
            'verification_state' => VerificationState::UnderLevel1Review,
            'returned_to_level1_to_user_id' => $l1->id,
            'returned_to_level1_by_user_id' => $l2->id,
            'returned_to_level1_at' => now(),
            'level1_correction_cycle' => 1,
            'level2_review_owner_id' => null,
        ]);

        $this->assertNotContains($qualification->id, $this->assignedToMeIds($l2));
    }

    public function test_level2_does_not_see_qualification_returned_to_applicant(): void
    {
        $l2 = $this->makeLevel2Officer();
        $qualification = $this->makeQualification($this->makeSubmittedApplication(), [
            'verification_state' => VerificationState::ReturnedToApplicant,
            'send_back_by_user_id' => $l2->id,
            'level2_review_owner_id' => $l2->id,
        ]);

        $this->assertNotContains($qualification->id, $this->assignedToMeIds($l2));
    }

    public function test_level2_does_not_see_terminal_qualification_states(): void
    {
        $l2 = $this->makeLevel2Officer();
        $terminalStates = [
            VerificationState::ApprovedForCertificate,
            VerificationState::Rejected,
            VerificationState::CertificateIssued,
            VerificationState::Closed,
        ];

        foreach ($terminalStates as $state) {
            $qualification = $this->makeQualification($this->makeSubmittedApplication(), [
                'verification_state' => $state,
                'level2_review_owner_id' => $l2->id,
                'title_of_qualification' => 'Terminal '.$state->value,
            ]);

            $this->assertNotContains(
                $qualification->id,
                $this->assignedToMeIds($l2),
                'Unexpected terminal qualification in assigned-to-me: '.$state->value,
            );
        }
    }

    public function test_dashboard_assigned_to_me_metrics_are_unchanged(): void
    {
        $l1 = $this->makeLevel1Officer();
        $l2 = $this->makeLevel2Officer();
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $this->makeQualification($this->makeSubmittedApplication(['applicant_user_id' => $applicant->id]), [
            'assigned_verifier_id' => $l1->id,
            'verification_state' => VerificationState::AssignedToLevel1,
        ]);
        $this->makeQualification($this->makeSubmittedApplication(['applicant_user_id' => $applicant->id]), [
            'assigned_verifier_id' => $l1->id,
            'verification_state' => VerificationState::UnderLevel1Review,
        ]);
        $this->makeQualification($this->makeSubmittedApplication(['applicant_user_id' => $applicant->id]), [
            'assigned_verifier_id' => $l1->id,
            'verification_state' => VerificationState::UnderLevel2Review,
            'level2_review_owner_id' => $l2->id,
        ]);

        $l1Dashboard = $this->actingAs($l1)->get('/admin/dashboard');
        $l1Dashboard->assertOk();
        $l1Props = json_decode(json_encode($l1Dashboard->viewData('page')), true)['props'];
        $l1Assigned = collect($l1Props['kpis'])->firstWhere('key', 'l1_assigned_to_me');
        $this->assertSame(2, (int) $l1Assigned['value']);

        $l2Dashboard = $this->actingAs($l2)->get('/admin/dashboard');
        $l2Dashboard->assertOk();
        $l2Props = json_decode(json_encode($l2Dashboard->viewData('page')), true)['props'];
        $l2Assigned = collect($l2Props['kpis'])->firstWhere('key', 'l2_assigned_to_me');
        $this->assertSame(1, (int) $l2Assigned['value']);
    }

    public function test_verification_pool_still_lists_open_qualifications(): void
    {
        $l2 = $this->makeLevel2Officer();
        $qualification = $this->makeQualification($this->makeSubmittedApplication(), [
            'verification_state' => VerificationState::AwaitingAssignment,
        ]);

        $this->actingAs($l2)
            ->get(route('admin.verification.pool.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('qualifications.data', 1)
                ->where('qualifications.data.0.id', $qualification->id));
    }

    public function test_assigned_to_me_page_shows_updated_empty_state_copy(): void
    {
        $l1 = $this->makeLevel1Officer();

        $this->actingAs($l1)
            ->get(route('admin.verification.assigned_to_me'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Verification/AssignedToMe')
                ->where('pageVariant', 'assigned')
                ->has('qualifications.data', 0));
    }
}
