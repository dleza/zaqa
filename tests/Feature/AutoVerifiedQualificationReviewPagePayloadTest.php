<?php

namespace Tests\Feature;

use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\LearnerRecord;
use App\Models\Qualification;
use App\Models\QualificationType;
use App\Models\User;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\FeeStructuresSeeder;
use Database\Seeders\QualificationTypesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutoVerifiedQualificationReviewPagePayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_review_page_includes_auto_verification_and_lock_payload(): void
    {
        $this->seed(BillingCategoriesSeeder::class);
        $this->seed(QualificationTypesSeeder::class);
        $this->seed(FeeStructuresSeeder::class);
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

        $type = QualificationType::query()->where('is_active', true)->orderBy('id')->firstOrFail();

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

        $record = LearnerRecord::query()->create([
            'awarding_institution_id' => $inst->id,
            'student_id' => 'STU-001',
            'program_of_study' => 'Diploma in Testing',
            'year_awarded' => 2024,
            'source_type' => 'manual',
            'is_active' => true,
        ]);

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_id' => $inst->id,
            'awarding_institution_name' => $inst->name,
            'qualification_holder_name' => 'John Doe',
            'nrc_passport_number' => '111111/11/1',
            'student_number' => 'STU-001',
            'certificate_number' => null,
            'title_of_qualification' => 'Diploma in Testing',
            'award_date' => '2024-01-10',
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'verification_state' => VerificationState::AutoVerifiedPendingLevel2,
            'learner_record_id' => $record->id,
            'auto_verification_confidence' => 80,
            'auto_verification_status' => 'matched',
            'auto_verification_match_summary' => ['matched_fields' => ['student_id' => true]],
            'verification_source' => 'internal_learner_record',
        ]);

        $res = $this->get("/admin/verification/qualifications/{$qualification->id}");
        $res->assertOk();
        $res->assertInertia(fn ($page) => $page
            ->component('Admin/Verification/Qualifications/Show')
            ->where('qualification.id', $qualification->id)
            ->has('qualification.auto_verification')
            ->has('qualification.level2_review_lock')
        );
    }
}
