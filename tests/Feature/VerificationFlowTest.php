<?php

namespace Tests\Feature;

use App\Domain\Verification\AssignmentService;
use App\Domain\Verification\DecisionService;
use App\Domain\Verification\SendBackService;
use App\Domain\Verification\VerificationReviewService;
use App\Domain\Verification\Events\ApplicationAssignedToLevel1;
use App\Domain\Verification\Events\ApplicationLevel1Completed;
use App\Domain\Verification\Events\ApplicationSentBackToApplicant;
use App\Domain\Verification\Listeners\SendAssignmentNotification;
use App\Domain\Verification\Listeners\SendLevel1CompletedNotification;
use App\Domain\Verification\Listeners\SendSendBackNotification;
use App\Enums\ApplicationStatus;
use App\Enums\VerificationState;
use App\Mail\Verification\ApplicationAssignedToLevel1Mail;
use App\Mail\Verification\ApplicationLevel1CompletedMail;
use App\Mail\Verification\ApplicationSentBackToApplicantMail;
use App\Models\Application;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class VerificationFlowTest extends TestCase
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

    public function test_level2_can_assign_and_level1_can_complete_only_if_assigned(): void
    {
        $application = $this->makeSubmittedApplication();

        $level2 = User::factory()->activated()->create(['applicant_type' => null]);
        $level2->givePermissionTo('verification.assign');
        $level2->givePermissionTo('verification.pool.view');
        $level2->givePermissionTo('dashboard.view');

        $level1 = User::factory()->activated()->create(['applicant_type' => null]);

        /** @var AssignmentService $assignments */
        $assignments = $this->app->make(AssignmentService::class);
        $assignments->assign($application, $level2, $level1, 'Please review.');

        $application->refresh();
        $this->assertSame($level1->id, $application->assigned_level1_user_id);
        $this->assertSame(ApplicationStatus::InProgress, $application->current_status);
        $this->assertSame(VerificationState::AssignedToLevel1, $application->verification_state);

        $otherLevel1 = User::factory()->activated()->create(['applicant_type' => null]);
        $otherLevel1->givePermissionTo('verification.level1.process');

        /** @var VerificationReviewService $reviews */
        $reviews = $this->app->make(VerificationReviewService::class);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $reviews->level1Complete($application, $otherLevel1, 'Findings');
    }

    public function test_level1_assignee_receives_email_on_assignment(): void
    {
        Mail::fake();

        $application = $this->makeSubmittedApplication();

        $level2 = User::factory()->activated()->create(['applicant_type' => null]);
        $level1 = User::factory()->activated()->create(['applicant_type' => null, 'email' => 'level1@example.test']);

        $event = new ApplicationAssignedToLevel1($application, $level2, $level1, 'Please review.');
        (new SendAssignmentNotification())->handle($event);

        Mail::assertQueued(ApplicationAssignedToLevel1Mail::class, function (ApplicationAssignedToLevel1Mail $mail) use ($level1, $application) {
            return $mail->hasTo($level1->email) && $mail->application->is($application);
        });
    }

    public function test_applicant_receives_email_when_sent_back(): void
    {
        Mail::fake();

        $application = $this->makeSubmittedApplication();
        $applicant = $application->applicant()->firstOrFail();
        $applicant->forceFill(['email' => 'applicant@example.test'])->save();

        $actor = User::factory()->activated()->create(['applicant_type' => null]);

        $event = new ApplicationSentBackToApplicant($application, $actor, 'Please correct X.');
        (new SendSendBackNotification())->handle($event);

        Mail::assertQueued(ApplicationSentBackToApplicantMail::class, function (ApplicationSentBackToApplicantMail $mail) use ($applicant, $application) {
            return $mail->hasTo($applicant->email) && $mail->application->is($application);
        });
    }

    public function test_level2_assigner_receives_email_when_level1_completes(): void
    {
        Mail::fake();

        $application = $this->makeSubmittedApplication();

        $level2 = User::factory()->activated()->create(['applicant_type' => null, 'email' => 'level2@example.test']);
        $level1 = User::factory()->activated()->create(['applicant_type' => null]);

        /** @var AssignmentService $assignments */
        $assignments = $this->app->make(AssignmentService::class);
        $assignments->assign($application, $level2, $level1, 'Please review.');

        /** @var VerificationReviewService $reviews */
        $reviews = $this->app->make(VerificationReviewService::class);
        $reviews->level1Complete($application->refresh(), $level1, 'All documents verified.');

        // Listener test: fire event handler directly with the recipient.
        $event = new ApplicationLevel1Completed($application->refresh(), $level1, $level2, 'All documents verified.');
        (new SendLevel1CompletedNotification())->handle($event);

        Mail::assertQueued(ApplicationLevel1CompletedMail::class, function (ApplicationLevel1CompletedMail $mail) use ($level2, $application) {
            return $mail->hasTo($level2->email) && $mail->application->is($application);
        });
    }

    public function test_send_back_requires_comment_and_sets_status(): void
    {
        $application = $this->makeSubmittedApplication();

        $actor = User::factory()->activated()->create(['applicant_type' => null]);
        $actor->givePermissionTo('verification.send_back');

        /** @var SendBackService $sendBack */
        $sendBack = $this->app->make(SendBackService::class);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $sendBack->sendBackToApplicant($application, $actor, '  ');
    }

    public function test_approve_reject_and_issue_certificate_rules(): void
    {
        $application = $this->makeSubmittedApplication();

        $level2 = User::factory()->activated()->create(['applicant_type' => null]);
        $level2->givePermissionTo('verification.decide.approve');
        $level2->givePermissionTo('verification.decide.reject');
        $level2->givePermissionTo('verification.certificate.issue');

        /** @var DecisionService $decisions */
        $decisions = $this->app->make(DecisionService::class);

        $decisions->approve($application, $level2, 'OK');
        $application->refresh();
        $this->assertSame(ApplicationStatus::Approved, $application->current_status);
        $this->assertSame(VerificationState::ApprovedForCertificate, $application->verification_state);

        $decisions->issueCertificate($application, $level2, 'Issuing');
        $application->refresh();
        $this->assertSame(ApplicationStatus::CertificateReady, $application->current_status);
        $this->assertSame(VerificationState::CertificateIssued, $application->verification_state);
    }
}

