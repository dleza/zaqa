<?php

namespace Tests\Feature;

use App\Enums\ApplicantType;
use App\Enums\ApplicationStatus;
use App\Enums\ServiceType;
use App\Models\Application;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApplicantWizardDeclarationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_applicant_can_save_wizard_declarations_and_metadata_is_set(): void
    {
        $user = User::factory()->activated()->create([
            'applicant_type' => ApplicantType::Individual,
        ]);

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-TEST-WD-1',
            'applicant_user_id' => $user->id,
            'applicant_type' => ApplicantType::Individual,
            'service_type' => ServiceType::Verification,
            'qualification_category' => 'test',
            'current_status' => ApplicationStatus::Draft,
            'is_foreign' => false,
            'metadata' => [],
        ]);

        $this->actingAs($user)
            ->patch("/applicant/applications/{$application->id}/wizard-declarations", [
                'accept_terms' => true,
                'confirm_information_correct' => true,
            ])
            ->assertRedirect();

        $application->refresh();
        $wd = (array) (($application->metadata ?? [])['wizard_declarations'] ?? []);
        $this->assertNotEmpty($wd['terms_accepted_at'] ?? null);
        $this->assertNotEmpty($wd['information_confirmed_at'] ?? null);
    }

    public function test_wizard_declarations_validation_requires_both_accepted(): void
    {
        $user = User::factory()->activated()->create([
            'applicant_type' => ApplicantType::Individual,
        ]);

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-TEST-WD-2',
            'applicant_user_id' => $user->id,
            'applicant_type' => ApplicantType::Individual,
            'service_type' => ServiceType::Verification,
            'qualification_category' => 'test',
            'current_status' => ApplicationStatus::Draft,
            'is_foreign' => false,
            'metadata' => [],
        ]);

        $this->actingAs($user)
            ->patch("/applicant/applications/{$application->id}/wizard-declarations", [
                'accept_terms' => true,
                'confirm_information_correct' => false,
            ])
            ->assertSessionHasErrors(['confirm_information_correct']);
    }
}
