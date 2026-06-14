<?php

namespace Tests\Feature;

use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\LearnerRecord;
use App\Models\Qualification;
use App\Models\QualificationSubjectResult;
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
            ->has('qualification.certificate_template')
        );
    }

    public function test_review_page_exposes_school_certificate_template_warning_when_subjects_missing(): void
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

        $type = QualificationType::query()->where('zqf_level_code', 'L1')->firstOrFail();

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-VER-'.rand(1000, 9999),
            'applicant_user_id' => $level2->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'certificate',
            'current_status' => 'submitted',
            'verification_state' => VerificationState::ApprovedForCertificate,
            'is_foreign' => false,
            'metadata' => ['verification_subject' => ['full_name' => 'Jane Doe']],
            'submitted_at' => now(),
        ]);

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'country_id' => $country->id,
            'awarding_institution_name' => 'Test School',
            'qualification_holder_name' => 'Jane Doe',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => $type->name,
            'award_date' => '2024-01-10',
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'verification_state' => VerificationState::ApprovedForCertificate,
        ]);

        $res = $this->get("/admin/verification/qualifications/{$qualification->id}");
        $res->assertOk();
        $res->assertInertia(fn ($page) => $page
            ->component('Admin/Verification/Qualifications/Show')
            ->where('qualification.certificate_template.key', 'school_subjects')
            ->where('qualification.certificate_template.subject_count', 0)
            ->where('qualification.certificate_template.warning', 'This certificate type requires subject results before issuing.')
        );

        QualificationSubjectResult::query()->create([
            'qualification_id' => $qualification->id,
            'subject_name' => 'Mathematics',
            'grade' => '1',
            'display_order' => 1,
        ]);

        $res = $this->get("/admin/verification/qualifications/{$qualification->id}");
        $res->assertOk();
        $res->assertInertia(fn ($page) => $page
            ->component('Admin/Verification/Qualifications/Show')
            ->where('qualification.certificate_template.key', 'school_subjects')
            ->where('qualification.certificate_template.subject_count', 1)
            ->where('qualification.certificate_template.warning', null)
        );
    }

    public function test_review_page_exposes_subject_results_for_school_qualifications(): void
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

        $type = QualificationType::query()->where('zqf_level_code', 'L2B')->firstOrFail();

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-VER-'.rand(1000, 9999),
            'applicant_user_id' => $level2->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'certificate',
            'current_status' => 'submitted',
            'verification_state' => VerificationState::ApprovedForCertificate,
            'is_foreign' => false,
            'metadata' => ['verification_subject' => ['full_name' => 'Jane Doe']],
            'submitted_at' => now(),
        ]);

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'country_id' => $country->id,
            'awarding_institution_name' => 'Test School',
            'qualification_holder_name' => 'Jane Doe',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => $type->name,
            'award_date' => '2024-01-10',
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'verification_state' => VerificationState::ApprovedForCertificate,
        ]);

        QualificationSubjectResult::query()->create([
            'qualification_id' => $qualification->id,
            'subject_name' => 'English Language',
            'grade' => '2',
            'display_order' => 2,
        ]);

        QualificationSubjectResult::query()->create([
            'qualification_id' => $qualification->id,
            'subject_name' => 'Mathematics',
            'grade' => '1',
            'display_order' => 1,
        ]);

        $res = $this->get("/admin/verification/qualifications/{$qualification->id}");
        $res->assertOk();
        $res->assertInertia(fn ($page) => $page
            ->component('Admin/Verification/Qualifications/Show')
            ->where('qualification.certificate_template.key', 'school_subjects')
            ->has('qualification.subject_results', 2)
            ->where('qualification.subject_results.0.subject_name', 'Mathematics')
            ->where('qualification.subject_results.0.grade', '1')
            ->where('qualification.subject_results.1.subject_name', 'English Language')
            ->where('qualification.subject_results.1.grade', '2')
        );
    }

    public function test_review_page_exposes_qualification_sla_with_application_fallback(): void
    {
        $this->seed(BillingCategoriesSeeder::class);
        $this->seed(QualificationTypesSeeder::class);
        $this->seed(FeeStructuresSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $level2 = User::factory()->activated()->create(['applicant_type' => null]);
        $level2->assignRole('Verification Officer Level 2');
        $this->actingAs($level2);

        $type = QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-VER-'.rand(1000, 9999),
            'applicant_user_id' => $level2->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => 'submitted',
            'verification_state' => VerificationState::UnderLevel1Review,
            'is_foreign' => false,
            'metadata' => ['verification_subject' => ['full_name' => 'Jane Doe']],
            'submitted_at' => now()->subDays(2),
            'service_deadline_at' => now()->addDays(12),
        ]);

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Fallback Test Institute',
            'qualification_holder_name' => 'Jane Doe',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => 'Diploma in Testing',
            'award_date' => '2024-01-10',
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'verification_state' => VerificationState::UnderLevel1Review,
        ]);

        $res = $this->get("/admin/verification/qualifications/{$qualification->id}");
        $res->assertOk();
        $res->assertInertia(fn ($page) => $page
            ->component('Admin/Verification/Qualifications/Show')
            ->where('qualification.service_started_at', $application->submitted_at?->toIso8601String())
            ->where('qualification.service_deadline_at', $application->service_deadline_at?->toIso8601String())
        );
    }
}
