<?php

namespace Tests\Feature;

use App\Domain\Applicant\ApplicantDashboardService;
use App\Domain\Applicant\ApplicantQualificationsService;
use App\Enums\ApplicationStatus;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\Qualification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ApplicantDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function makeApplicant(): User
    {
        return User::factory()->activated()->create(['applicant_type' => 'individual']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeApplication(User $applicant, array $overrides = []): Application
    {
        return Application::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-APP-'.Str::upper(Str::random(6)),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Draft,
            'is_foreign' => false,
            'metadata' => [],
        ], $overrides));
    }

    private function makeQualification(Application $application, array $overrides = []): Qualification
    {
        return Qualification::query()->create(array_merge([
            'application_id' => $application->id,
            'verification_reference_number' => 'ZAQA-APP-'.Str::upper(Str::random(8)),
            'awarding_institution_name' => 'Test Institution',
            'qualification_holder_name' => 'Holder',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => Str::random(6).'/11/1',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'verification_state' => VerificationState::AwaitingAssignment,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ], $overrides));
    }

    public function test_dashboard_payload_uses_qualification_counts_and_tracking_href(): void
    {
        $applicant = $this->makeApplicant();

        $draft = $this->makeApplication($applicant, ['current_status' => ApplicationStatus::Draft]);
        $this->makeQualification($draft, ['title_of_qualification' => 'Draft qual']);

        $submitted = $this->makeApplication($applicant, ['current_status' => ApplicationStatus::Submitted, 'submitted_at' => now()]);
        $this->makeQualification($submitted, ['verification_state' => VerificationState::AssignedToLevel1, 'title_of_qualification' => 'Processing A']);
        $this->makeQualification($submitted, ['verification_state' => VerificationState::UnderLevel2Review, 'title_of_qualification' => 'Processing B']);

        $sentBackApp = $this->makeApplication($applicant, ['current_status' => ApplicationStatus::SentBack]);
        $this->makeQualification($sentBackApp, ['verification_state' => VerificationState::ReturnedToApplicant, 'title_of_qualification' => 'Returned qual']);

        $approvedApp = $this->makeApplication($applicant, ['current_status' => ApplicationStatus::Approved]);
        $this->makeQualification($approvedApp, ['verification_state' => VerificationState::CertificateIssued, 'title_of_qualification' => 'Completed qual']);

        $latest = $this->makeApplication($applicant, ['current_status' => ApplicationStatus::CertificateReady]);

        $this->actingAs($applicant)
            ->get('/applicant/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Applicant/Dashboard', false)
                ->has('counts', fn (Assert $counts) => $counts
                    ->where('total', 4)
                    ->where('draft', 1)
                    ->where('processing', 2)
                    ->where('sent_back', 1)
                    ->where('completed', 1)
                    ->missing('approved')
                    ->missing('submitted')
                )
                ->where('trackingHref', route('applicant.applications.track', $latest))
            );
    }

    public function test_processing_count_includes_in_progress_qualifications_and_excludes_completed_or_returned(): void
    {
        $applicant = $this->makeApplicant();
        $application = $this->makeApplication($applicant, [
            'current_status' => ApplicationStatus::InProgress,
            'submitted_at' => now(),
        ]);

        $this->makeQualification($application, ['verification_state' => VerificationState::AwaitingAssignment]);
        $this->makeQualification($application, ['verification_state' => VerificationState::UnderLevel1Review]);
        $this->makeQualification($application, ['verification_state' => null]);
        $this->makeQualification($application, ['verification_state' => VerificationState::ReturnedToApplicant]);
        $this->makeQualification($application, ['verification_state' => VerificationState::ApprovedForCertificate]);
        $this->makeQualification($application, ['verification_state' => VerificationState::Rejected]);
        $this->makeQualification($application, ['verification_state' => VerificationState::CertificateIssued]);

        $payload = app(ApplicantDashboardService::class)->build($applicant);

        $this->assertSame(3, $payload['counts']['processing']);
    }

    public function test_processing_count_excludes_qualifications_on_draft_or_completed_applications(): void
    {
        $applicant = $this->makeApplicant();

        $draft = $this->makeApplication($applicant, ['current_status' => ApplicationStatus::Draft]);
        $this->makeQualification($draft, ['verification_state' => VerificationState::AwaitingAssignment]);

        $approved = $this->makeApplication($applicant, ['current_status' => ApplicationStatus::Approved]);
        $this->makeQualification($approved, ['verification_state' => VerificationState::UnderLevel1Review]);

        $payload = app(ApplicantDashboardService::class)->build($applicant);

        $this->assertSame(0, $payload['counts']['processing']);
        $this->assertSame(1, $payload['counts']['draft']);
    }

    public function test_completed_count_uses_terminal_qualification_states(): void
    {
        $applicant = $this->makeApplicant();
        $application = $this->makeApplication($applicant, [
            'current_status' => ApplicationStatus::InProgress,
            'submitted_at' => now(),
        ]);

        $this->makeQualification($application, ['verification_state' => VerificationState::ApprovedForCertificate]);
        $this->makeQualification($application, ['verification_state' => VerificationState::Rejected]);
        $this->makeQualification($application, ['verification_state' => VerificationState::CertificateIssued]);
        $this->makeQualification($application, ['verification_state' => VerificationState::Closed]);
        $this->makeQualification($application, ['verification_state' => VerificationState::UnderLevel1Review]);

        $payload = app(ApplicantQualificationsService::class)->countsFor($applicant);

        $this->assertSame(4, $payload['completed']);
        $this->assertSame(1, $payload['processing']);
    }

    public function test_qualifications_index_lists_processing_qualifications(): void
    {
        $applicant = $this->makeApplicant();
        $application = $this->makeApplication($applicant, [
            'current_status' => ApplicationStatus::Submitted,
            'submitted_at' => now(),
        ]);

        $processing = $this->makeQualification($application, [
            'verification_state' => VerificationState::UnderLevel1Review,
            'title_of_qualification' => 'Bachelor of Science',
        ]);
        $this->makeQualification($application, [
            'verification_state' => VerificationState::CertificateIssued,
            'title_of_qualification' => 'Already done',
        ]);

        $this->actingAs($applicant)
            ->get('/applicant/qualifications?filter=processing')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Applicant/Qualifications/Index', false)
                ->where('filter', 'processing')
                ->has('qualifications', 1)
                ->where('qualifications.0.id', $processing->id)
                ->where('qualifications.0.title_of_qualification', 'Bachelor of Science')
                ->where('qualifications.0.status_label', 'Processing')
                ->where('qualifications.0.href', route('applicant.applications.track', $application))
            );
    }

    public function test_qualifications_index_total_shows_all_submitted_qualifications(): void
    {
        $applicant = $this->makeApplicant();

        $draft = $this->makeApplication($applicant, ['current_status' => ApplicationStatus::Draft]);
        $this->makeQualification($draft, ['title_of_qualification' => 'Draft only']);

        $submitted = $this->makeApplication($applicant, ['current_status' => ApplicationStatus::Submitted, 'submitted_at' => now()]);
        $this->makeQualification($submitted, ['title_of_qualification' => 'Submitted qual']);

        $this->actingAs($applicant)
            ->get('/applicant/qualifications?filter=total')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Applicant/Qualifications/Index', false)
                ->has('qualifications', 1)
                ->where('qualifications.0.title_of_qualification', 'Submitted qual')
                ->where('counts.total', 1)
            );
    }

    public function test_tracking_href_falls_back_to_applications_index_when_no_applications(): void
    {
        $applicant = $this->makeApplicant();

        $payload = app(ApplicantDashboardService::class)->build($applicant);

        $this->assertSame(route('applicant.applications.index'), $payload['tracking_href']);
    }

    public function test_dashboard_vue_template_links_stat_tiles_to_qualifications_list(): void
    {
        $contents = file_get_contents(resource_path('js/Pages/Applicant/Dashboard.vue'));

        $this->assertIsString($contents);
        $this->assertStringContainsString("const qualificationsBase = '/applicant/qualifications'", $contents);
        $this->assertStringContainsString('filter=processing', $contents);
        $this->assertStringContainsString('filter=total', $contents);
        $this->assertStringContainsString("label: 'Processing'", $contents);
        $this->assertStringNotContainsString("href: '/applicant/applications'", $contents);
    }

    public function test_applicant_can_still_access_applications_and_invoices_from_sidebar_routes(): void
    {
        $applicant = $this->makeApplicant();

        $this->actingAs($applicant)
            ->get('/applicant/applications')
            ->assertOk();

        $this->actingAs($applicant)
            ->get('/applicant/invoices')
            ->assertOk();
    }

    public function test_dashboard_includes_trackable_qualifications_for_open_items(): void
    {
        $applicant = $this->makeApplicant();
        $application = $this->makeApplication($applicant, [
            'current_status' => ApplicationStatus::InProgress,
            'submitted_at' => now(),
        ]);

        $open = $this->makeQualification($application, [
            'verification_state' => VerificationState::UnderLevel1Review,
            'title_of_qualification' => 'Open Diploma',
        ]);
        $this->makeQualification($application, [
            'verification_state' => VerificationState::CertificateIssued,
            'title_of_qualification' => 'Closed Diploma',
        ]);

        $this->actingAs($applicant)
            ->get('/applicant/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('trackableQualifications', 1)
                ->where('trackableQualifications.0.id', $open->id)
                ->where('trackableQualifications.0.title_of_qualification', 'Open Diploma')
                ->where('trackableQualifications.0.status_label', 'Processing')
                ->where('trackableQualifications.0.href', route('applicant.applications.track', [
                    'application' => $application,
                    'qualification' => $open->id,
                ]))
            );
    }

    public function test_tracking_route_remains_accessible_for_submitted_application(): void
    {
        $applicant = $this->makeApplicant();
        $application = $this->makeApplication($applicant, [
            'current_status' => ApplicationStatus::Submitted,
            'submitted_at' => now(),
        ]);

        $this->actingAs($applicant)
            ->get("/applicant/applications/{$application->id}/track")
            ->assertOk();
    }
}
