<?php

namespace Tests\Feature;

use App\Domain\Verification\AssignmentService;
use App\Domain\Verification\QualificationLevel1ReviewService;
use App\Domain\Verification\QualificationSendBackService;
use App\Enums\ApplicationStatus;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\Qualification;
use App\Models\User;
use App\Notifications\Verification\QualificationAssignedPortalNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class VerificationQualificationNotificationsTest extends TestCase
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

    public function test_level1_assignee_receives_portal_notification_and_email_on_assignment(): void
    {
        Mail::fake();

        $application = $this->makeSubmittedApplication();
        $qualification = $this->makeQualification($application);

        $level2 = User::factory()->activated()->create(['applicant_type' => null]);
        $level2->givePermissionTo('verification.assign');

        $level1 = User::factory()->activated()->create([
            'applicant_type' => null,
            'email' => 'level1@example.test',
        ]);

        /** @var AssignmentService $assignments */
        $assignments = $this->app->make(AssignmentService::class);
        $assignments->assign($qualification, $level2, $level1, 'Please review.');

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $level1->id,
            'type' => 'verification.qualification_assigned',
            'read_at' => null,
        ]);

        $this->assertSame(1, $level1->fresh()->unreadNotifications()->count());

        Mail::assertQueued(\App\Mail\Verification\QualificationAssignedToVerifierMail::class);
    }

    public function test_level2_assigner_receives_portal_notification_and_email_when_level1_completes(): void
    {
        Mail::fake();

        $application = $this->makeSubmittedApplication();
        $qualification = $this->makeQualification($application);

        $level2 = User::factory()->activated()->create([
            'applicant_type' => null,
            'email' => 'level2@example.test',
        ]);
        $level2->givePermissionTo('verification.assign');

        $level1 = User::factory()->activated()->create(['applicant_type' => null]);

        /** @var AssignmentService $assignments */
        $assignments = $this->app->make(AssignmentService::class);
        $assignments->assign($qualification, $level2, $level1, 'Please review.');

        /** @var QualificationLevel1ReviewService $reviews */
        $reviews = $this->app->make(QualificationLevel1ReviewService::class);
        $reviews->completeLevel1($qualification, $level1, 'All documents match. Recommend approval.', null);

        $qualification->refresh();
        $this->assertSame($level2->id, $qualification->level2_review_owner_id);

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $level2->id,
            'type' => 'verification.qualification_level1_completed',
            'read_at' => null,
        ]);

        Mail::assertQueued(\App\Mail\Verification\QualificationLevel1CompletedMail::class);
    }

    public function test_reassignment_optionally_notifies_previous_assignee(): void
    {
        Mail::fake();

        $application = $this->makeSubmittedApplication();
        $qualification = $this->makeQualification($application);

        $level2 = User::factory()->activated()->create(['applicant_type' => null]);
        $level2->givePermissionTo('verification.assign');

        $level1a = User::factory()->activated()->create(['applicant_type' => null, 'email' => 'l1a@example.test']);
        $level1b = User::factory()->activated()->create(['applicant_type' => null, 'email' => 'l1b@example.test']);

        /** @var AssignmentService $assignments */
        $assignments = $this->app->make(AssignmentService::class);
        $assignments->assign($qualification, $level2, $level1a, 'First pass.');
        $assignments->assign($qualification, $level2, $level1b, 'Reassigning.');

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $level1b->id,
            'type' => 'verification.qualification_assigned',
        ]);

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $level1a->id,
            'type' => 'verification.qualification_reassigned',
        ]);
    }

    public function test_applicant_receives_portal_notification_when_qualification_sent_back(): void
    {
        $application = $this->makeSubmittedApplication();
        $qualification = $this->makeQualification($application);

        $actor = User::factory()->activated()->create(['applicant_type' => null]);
        $actor->givePermissionTo('verification.level2.review');

        /** @var QualificationSendBackService $sendBack */
        $sendBack = $this->app->make(QualificationSendBackService::class);
        $sendBack->sendBackToApplicant($qualification, $actor, 'Please attach a clearer certificate scan.');

        $applicant = $application->applicant()->firstOrFail();

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $applicant->id,
            'type' => 'verification.qualification_sent_back_to_applicant',
            'read_at' => null,
        ]);
    }

    public function test_notification_mark_read_is_scoped_to_user(): void
    {
        $userA = User::factory()->activated()->create(['applicant_type' => null]);
        $userA->givePermissionTo('dashboard.view');

        $userB = User::factory()->activated()->create(['applicant_type' => null]);
        $userB->givePermissionTo('dashboard.view');

        $userB->notify(new QualificationAssignedPortalNotification(
            qualificationId: 123,
            applicationReference: 'ZAQA-VER-0001',
            qualificationTitle: 'Test qualification',
            qualificationType: 'L6',
            awardingInstitution: 'Test Institute',
            assignedByName: 'Level 2 Officer',
            comment: null,
        ));

        $note = $userB->fresh()->unreadNotifications()->firstOrFail();

        $this->actingAs($userA)
            ->post("/admin/notifications/{$note->id}/read")
            ->assertNotFound();

        $this->actingAs($userB)
            ->post("/admin/notifications/{$note->id}/read")
            ->assertRedirect();

        $this->assertSame(0, $userB->fresh()->unreadNotifications()->count());
    }
}
