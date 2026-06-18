<?php

namespace Tests\Feature;

use App\Domain\Applications\ApplicationQualificationOutcomeSyncService;
use App\Enums\ApplicationStatus;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\Qualification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApplicationQualificationOutcomeSyncTest extends TestCase
{
    use RefreshDatabase;

    private function makeSubmittedApplication(ApplicationStatus $status = ApplicationStatus::InProgress): Application
    {
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        return Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-SYNC-'.Str::upper(Str::random(6)),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => $status,
            'verification_state' => VerificationState::UnderLevel2Review,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
        ]);
    }

    private function makeQualification(Application $application, VerificationState $state): Qualification
    {
        return Qualification::query()->create([
            'application_id' => $application->id,
            'verification_reference_number' => 'ZAQA-SYNC-'.Str::upper(Str::random(8)),
            'awarding_institution_name' => 'Test Institution',
            'qualification_holder_name' => 'Holder',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => Str::random(6).'/11/1',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'verification_state' => $state,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ]);
    }

    public function test_sync_marks_application_completed_when_qualifications_have_mixed_terminal_outcomes(): void
    {
        $application = $this->makeSubmittedApplication();
        $this->makeQualification($application, VerificationState::ApprovedForCertificate);
        $this->makeQualification($application, VerificationState::Rejected);

        $synced = app(ApplicationQualificationOutcomeSyncService::class)->syncIfNeeded($application->fresh());

        $this->assertSame(ApplicationStatus::Completed, $synced->current_status);
        $this->assertSame('Completed — 1 approved, 1 rejected', $synced->applicantDisplayStatusLabel());
    }

    public function test_sync_marks_application_rejected_when_all_qualifications_rejected(): void
    {
        $application = $this->makeSubmittedApplication();
        $this->makeQualification($application, VerificationState::Rejected);
        $this->makeQualification($application, VerificationState::Rejected);

        $synced = app(ApplicationQualificationOutcomeSyncService::class)->syncIfNeeded($application->fresh());

        $this->assertSame(ApplicationStatus::Rejected, $synced->current_status);
        $this->assertSame('Rejected', $synced->applicantDisplayStatusLabel());
    }

    public function test_sync_marks_application_approved_when_all_qualifications_await_certificates(): void
    {
        $application = $this->makeSubmittedApplication();
        $this->makeQualification($application, VerificationState::ApprovedForCertificate);
        $this->makeQualification($application, VerificationState::ApprovedForCertificate);

        $synced = app(ApplicationQualificationOutcomeSyncService::class)->syncIfNeeded($application->fresh());

        $this->assertSame(ApplicationStatus::Approved, $synced->current_status);
        $this->assertSame('Approved — awaiting certificate(s)', $synced->applicantDisplayStatusLabel());
    }

    public function test_sync_leaves_application_in_progress_when_a_qualification_is_still_processing(): void
    {
        $application = $this->makeSubmittedApplication();
        $this->makeQualification($application, VerificationState::Rejected);
        $this->makeQualification($application, VerificationState::UnderLevel2Review);

        $synced = app(ApplicationQualificationOutcomeSyncService::class)->syncIfNeeded($application->fresh());

        $this->assertSame(ApplicationStatus::InProgress, $synced->current_status);
    }

    public function test_applicant_application_show_reflects_synced_completed_status(): void
    {
        $application = $this->makeSubmittedApplication();
        $this->makeQualification($application, VerificationState::CertificateIssued);
        $this->makeQualification($application, VerificationState::Rejected);
        $applicant = User::query()->findOrFail($application->applicant_user_id);

        $response = $this->actingAs($applicant)->get('/applicant/applications/'.$application->id);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('application.current_status', ApplicationStatus::Completed->value)
            ->where('application.display_status_label', 'Completed — 1 approved, 1 rejected')
            ->has('application.qualifications', 2)
            ->where('application.qualifications.0.status_label', 'Certificate issued')
            ->where('application.qualifications.1.status_label', 'Rejected')
        );
    }
}
