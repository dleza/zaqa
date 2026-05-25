<?php

namespace Tests\Feature;

use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\AwardingInstitution;
use App\Models\BillingCategory;
use App\Models\Country;
use App\Models\Qualification;
use App\Models\QualificationType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutoVerifiedPendingReviewQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_level2_can_access_auto_verified_queue_and_only_lists_pending_level2_items(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $level2 = User::factory()->activated()->create(['applicant_type' => null]);
        $level2->assignRole('Verification Officer Level 2');
        $this->actingAs($level2);

        $country = Country::query()->create([
            'iso_code' => 'ZMB',
            'name' => 'Zambia',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $inst = AwardingInstitution::query()->create([
            'country_id' => $country->id,
            'name' => 'Test University',
            'consent_form_path' => null,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $billing = BillingCategory::query()->create([
            'name' => 'Test Billing',
            'code' => 'TEST_BILLING',
            'description' => null,
            'local_processing_days' => 10,
            'foreign_processing_days' => 20,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $type = QualificationType::query()->create([
            'name' => 'Test Type',
            'zqf_level_code' => 'L6',
            'level_label' => 'Level 6',
            'billing_category_id' => $billing->id,
            'is_active' => true,
            'requires_subject_results' => false,
            'sort_order' => 1,
        ]);

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-VER-'.rand(1000, 9999),
            'applicant_user_id' => $level2->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => 'submitted',
            'verification_state' => VerificationState::AwaitingAutoVerification,
            'is_foreign' => false,
            'metadata' => ['verification_subject' => ['full_name' => 'John Doe']],
            'submitted_at' => now(),
        ]);

        $pending = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_id' => $inst->id,
            'awarding_institution_name' => $inst->name,
            'qualification_holder_name' => 'John Doe',
            'nrc_passport_number' => '111111/11/1',
            'student_number' => 'STU-001',
            'title_of_qualification' => 'Diploma in Testing',
            'award_date' => '2024-01-10',
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'verification_state' => VerificationState::AutoVerifiedPendingLevel2,
            'auto_verification_confidence' => 80,
            'verification_source' => 'internal_learner_record',
        ]);

        Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_id' => $inst->id,
            'awarding_institution_name' => $inst->name,
            'qualification_holder_name' => 'John Doe',
            'nrc_passport_number' => '111111/11/1',
            'student_number' => 'STU-002',
            'title_of_qualification' => 'Diploma in Something Else',
            'award_date' => '2024-01-10',
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'verification_state' => VerificationState::AwaitingAssignment,
            'auto_verification_confidence' => 80,
            'verification_source' => 'internal_learner_record',
        ]);

        $res = $this->get('/admin/verification/auto-verified');
        $res->assertOk();
        $res->assertInertia(fn ($page) => $page
            ->component('Admin/Verification/AutoVerified/Index')
            ->has('qualifications.data', 1)
            ->where('qualifications.data.0.id', $pending->id)
        );
    }

    public function test_level1_cannot_access_auto_verified_queue(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $level1 = User::factory()->activated()->create(['applicant_type' => null]);
        $level1->assignRole('Verification Officer Level 1');
        $this->actingAs($level1);

        $this->get('/admin/verification/auto-verified')->assertStatus(403);
    }
}

