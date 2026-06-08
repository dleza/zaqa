<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\Qualification;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminApplicationsOutcomePoolsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeSubmittedApplication(string $number, ApplicationStatus $status = ApplicationStatus::Completed): Application
    {
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        return Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => $number,
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'mixed',
            'current_status' => $status,
            'verification_state' => VerificationState::Closed,
            'is_foreign' => false,
            'metadata' => [
                'verification_subject' => [
                    'full_name' => 'Applicant '.$number,
                ],
            ],
            'submitted_at' => now()->subDays(2),
            'paid_at' => now()->subDays(2),
            'service_deadline_at' => now()->addDays(7),
        ]);
    }

    private function makeQualification(Application $application, string $title, VerificationState $state): Qualification
    {
        return Qualification::query()->create([
            'application_id' => $application->id,
            'verification_reference_number' => 'VER-'.Str::upper(Str::random(8)),
            'awarding_institution_name' => 'Test Institution',
            'qualification_holder_name' => 'Holder '.$application->application_number,
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => $title,
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'verification_state' => $state,
        ]);
    }

    public function test_applications_pool_lists_only_submitted_applications_with_all_terminal_qualifications(): void
    {
        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->assignRole('Super Admin');

        $closedApplication = $this->makeSubmittedApplication('ZAQA-APP-1001', ApplicationStatus::Completed);
        $this->makeQualification($closedApplication, 'Diploma in Accounting', VerificationState::ApprovedForCertificate);
        $this->makeQualification($closedApplication, 'Certificate in Payroll', VerificationState::Rejected);

        $mixedApplication = $this->makeSubmittedApplication('ZAQA-APP-1002', ApplicationStatus::InProgress);
        $this->makeQualification($mixedApplication, 'BSc Computer Science', VerificationState::Rejected);
        $this->makeQualification($mixedApplication, 'Diploma in IT', VerificationState::UnderLevel2Review);

        $response = $this->actingAs($admin)->get('/admin/applications');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Applications/Index')
            ->has('applications.data', 1)
            ->where('applications.data.0.application_number', 'ZAQA-APP-1001')
            ->where('applications.data.0.qualification_count', 2)
            ->where('applications.data.0.approved_qualification_count', 1)
            ->where('applications.data.0.rejected_qualification_count', 1)
        );
    }

    public function test_closed_qualifications_page_lists_only_terminal_qualifications(): void
    {
        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->assignRole('Super Admin');

        $application = $this->makeSubmittedApplication('ZAQA-APP-2001', ApplicationStatus::Completed);
        $approved = $this->makeQualification($application, 'Advanced Certificate', VerificationState::ApprovedForCertificate);
        $rejected = $this->makeQualification($application, 'Ordinary Diploma', VerificationState::Rejected);
        $this->makeQualification($application, 'Pending Review', VerificationState::AssignedToLevel1);

        $response = $this->actingAs($admin)->get('/admin/applications/qualifications');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Applications/Qualifications')
            ->has('qualifications.data', 2)
            ->where('qualifications.data.0.id', $rejected->id)
            ->where('qualifications.data.1.id', $approved->id)
        );
    }
}
