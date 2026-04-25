<?php

namespace Tests\Feature;

use App\Enums\ApplicantType;
use App\Enums\ApplicationStatus;
use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ServiceFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\BillingCategoriesSeeder::class);
        $this->seed(\Database\Seeders\QualificationTypesSeeder::class);
        $this->seed(\Database\Seeders\FeeStructuresSeeder::class);
    }

    public function test_applicant_can_submit_feedback_once_per_application(): void
    {
        $user = User::factory()->activated()->create([
            'applicant_type' => ApplicantType::Individual,
        ]);

        ApplicantProfile::create([
            'user_id' => $user->id,
            'first_name' => 'Jane',
            'surname' => 'Doe',
            'nrc_number' => '111111/11/1',
            'email' => $user->email,
            'phone_primary' => $user->phone_primary,
        ]);

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'applicant_user_id' => $user->id,
            'applicant_type' => $user->applicant_type->value,
            'service_type' => 'verification',
            'qualification_category' => 'degree',
            'current_status' => ApplicationStatus::Submitted,
            'submitted_at' => now(),
            'application_number' => 'APP-TEST-0001',
        ]);

        $this->actingAs($user);

        $get = $this->get(route('applicant.applications.feedback.show', $application));
        $get->assertOk();

        $post = $this->post(route('applicant.applications.feedback.store', $application), [
            'rating_value' => 5,
            'rating_label' => 'Excellent',
            'feedback_text' => 'Smooth and clear.',
        ]);
        $post->assertRedirect(route('applicant.applications.show', $application));

        $this->assertDatabaseHas('service_feedback', [
            'application_id' => $application->id,
            'applicant_user_id' => $user->id,
            'rating_value' => 5,
        ]);

        $duplicate = $this->post(route('applicant.applications.feedback.store', $application), [
            'rating_value' => 4,
        ]);
        $duplicate->assertSessionHasErrors(['feedback']);
    }
}

