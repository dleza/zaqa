<?php

namespace Tests\Unit;

use App\Domain\Applications\InstitutionalMultipleOverviewService;
use App\Enums\ApplicantType;
use App\Enums\ApplicationStatus;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\Qualification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InstitutionalMultipleOverviewServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_cards_use_applicant_friendly_buckets(): void
    {
        $application = $this->makeInstitutionalApplication();

        $this->makeQualification($application, VerificationState::AwaitingAssignment, 'Awaiting');
        $this->makeQualification($application, VerificationState::AssignedToLevel1, 'Assigned L1');
        $this->makeQualification($application, VerificationState::UnderLevel1Review, 'Under L1');
        $this->makeQualification($application, VerificationState::UnderLevel2Review, 'Under L2');
        $this->makeQualification($application, VerificationState::AutoVerifiedPendingLevel2, 'Auto pending');
        $this->makeQualification($application, VerificationState::AwaitingAutoVerification, 'Auto verify');
        $this->makeQualification($application, VerificationState::ReturnedToApplicant, 'Returned');
        $this->makeQualification($application, VerificationState::ApprovedForCertificate, 'Approved');
        $this->makeQualification($application, VerificationState::CertificateIssued, 'Issued');
        $this->makeQualification($application, VerificationState::Rejected, 'Rejected');
        $this->makeQualification($application, VerificationState::Closed, 'Closed');

        $overview = app(InstitutionalMultipleOverviewService::class)->build($application->fresh('qualifications'));

        $this->assertSame(11, $overview['total_qualifications']);
        $this->assertSame(6, $overview['in_review']);
        $this->assertSame(1, $overview['returned_for_correction']);
        $this->assertSame(4, $overview['completed']);
        $this->assertArrayNotHasKey('pending_assignment', $overview);
        $this->assertArrayNotHasKey('under_level1', $overview);
        $this->assertArrayNotHasKey('under_level2', $overview);
    }

    public function test_row_status_labels_do_not_expose_internal_workflow_levels(): void
    {
        $application = $this->makeInstitutionalApplication();

        $this->makeQualification($application, VerificationState::UnderLevel1Review, 'Holder A');
        $this->makeQualification($application, VerificationState::UnderLevel2Review, 'Holder B');
        $this->makeQualification($application, VerificationState::ReturnedToApplicant, 'Holder C');
        $this->makeQualification($application, VerificationState::CertificateIssued, 'Holder D');

        $overview = app(InstitutionalMultipleOverviewService::class)->build($application->fresh('qualifications'));
        $labels = collect($overview['rows'])->pluck('status_label')->all();

        $this->assertContains('In review', $labels);
        $this->assertContains('Returned for correction', $labels);
        $this->assertContains('Certificate issued', $labels);
        $this->assertNotContains('Under Level 1 review', $labels);
        $this->assertNotContains('Under Level 2 review', $labels);
        $this->assertNotContains('Assigned to Level 1', $labels);
        $this->assertNotContains('Pending assignment', $labels);
    }

    private function makeInstitutionalApplication(): Application
    {
        $user = User::factory()->activated()->create(['applicant_type' => ApplicantType::Institution]);

        return Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-MULT-'.rand(1000, 9999),
            'applicant_user_id' => $user->id,
            'applicant_type' => ApplicantType::Institution,
            'service_type' => 'verification',
            'qualification_category' => 'institutional_multiple',
            'current_status' => ApplicationStatus::Submitted,
            'is_foreign' => false,
            'metadata' => ['submission_mode' => 'institutional_multiple'],
            'submitted_at' => now(),
        ]);
    }

    private function makeQualification(Application $application, VerificationState $state, string $holderName): Qualification
    {
        return Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test College',
            'qualification_holder_name' => $holderName,
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => 'Diploma '.$holderName,
            'names_as_on_qualification_document' => $holderName,
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'is_foreign_qualification' => false,
            'verification_state' => $state,
        ]);
    }
}
