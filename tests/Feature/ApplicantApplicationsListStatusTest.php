<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\Qualification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ApplicantApplicationsListStatusTest extends TestCase
{
    use RefreshDatabase;

    private function makeApplicant(): User
    {
        return User::factory()->activated()->create(['applicant_type' => 'individual']);
    }

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
            'submitted_at' => now(),
        ], $overrides));
    }

    public function test_applications_list_shows_processing_for_submitted_status(): void
    {
        $applicant = $this->makeApplicant();
        $application = $this->makeApplication($applicant, [
            'current_status' => ApplicationStatus::Submitted,
            'verification_state' => VerificationState::AwaitingAssignment,
        ]);

        $this->actingAs($applicant)
            ->get('/applicant/applications')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Applicant/Applications/Index')
                ->where('applications.0.id', $application->id)
                ->where('applications.0.list_status_label', 'Processing')
            );
    }

    public function test_applications_list_shows_processing_for_in_progress_status(): void
    {
        $applicant = $this->makeApplicant();
        $application = $this->makeApplication($applicant, [
            'current_status' => ApplicationStatus::InProgress,
            'verification_state' => VerificationState::AssignedToLevel1,
        ]);

        $this->actingAs($applicant)
            ->get('/applicant/applications')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('applications.0.list_status_label', 'Processing')
            );
    }

    public function test_applications_list_still_shows_correction_required(): void
    {
        $applicant = $this->makeApplicant();
        $application = $this->makeApplication($applicant, [
            'current_status' => ApplicationStatus::InProgress,
            'verification_state' => VerificationState::AssignedToLevel1,
        ]);

        Qualification::query()->create([
            'application_id' => $application->id,
            'verification_reference_number' => 'ZAQA-REF-001',
            'awarding_institution_name' => 'Test Institution',
            'qualification_holder_name' => 'Holder',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '123456/78/9',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'verification_state' => VerificationState::ReturnedToApplicant,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'service_deadline_at' => now()->addDays(5),
        ]);

        $this->actingAs($applicant)
            ->get('/applicant/applications')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('applications.0.list_status_label', 'Correction required')
            );
    }
}
