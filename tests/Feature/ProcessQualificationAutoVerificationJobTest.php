<?php

namespace Tests\Feature;

use App\Domain\Payments\ApplicationPaymentSatisfaction;
use App\Enums\LearnerRecordMatchStatus;
use App\Enums\LearnerRecordSourceType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\VerificationState;
use App\Jobs\Verification\ProcessQualificationAutoVerificationJob;
use App\Models\Application;
use App\Models\AwardingInstitution;
use App\Models\BillingCategory;
use App\Models\Country;
use App\Models\LearnerRecord;
use App\Models\LearnerRecordMatchAttempt;
use App\Models\Payment;
use App\Models\Qualification;
use App\Models\QualificationCertificate;
use App\Models\QualificationType;
use App\Models\User;
use App\Support\Normalization\LearnerRecordNormalizer;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\FeeStructuresSeeder;
use Database\Seeders\QualificationTypesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProcessQualificationAutoVerificationJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeAppWithQualification(User $applicant, AwardingInstitution $inst, QualificationType $type, array $overrides = []): array
    {
        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-VER-'.rand(1000, 9999),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => 'submitted',
            'verification_state' => VerificationState::AwaitingAutoVerification,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
            'service_deadline_at' => now()->addDays(14),
        ]);

        $title = $overrides['title_of_qualification'] ?? 'Diploma in Testing';
        $student = $overrides['student_number'] ?? 'STU-001';
        $awardDate = $overrides['award_date'] ?? '2024-01-10';

        $qualification = Qualification::query()->create(array_merge([
            'application_id' => $application->id,
            'awarding_institution_id' => $inst->id,
            'awarding_institution_name' => $inst->name,
            'qualification_holder_name' => 'John Doe',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'student_number' => $student,
            'certificate_number' => null,
            'examination_number' => null,
            'title_of_qualification' => $title,
            'award_date' => $awardDate,
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'verification_state' => VerificationState::AwaitingAutoVerification,
            'transcript_required' => false,
        ], $overrides));

        return [$application, $qualification];
    }

    public function test_job_matches_and_routes_to_level2_when_auto_issue_disabled(): void
    {
        config([
            'auto_verification.enabled' => true,
            'auto_verification.threshold' => 70,
            'auto_verification.auto_issue_enabled' => false,
        ]);

        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

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

        [$application, $qualification] = $this->makeAppWithQualification($applicant, $inst, $type);

        $title = $qualification->title_of_qualification;
        LearnerRecord::query()->create([
            'awarding_institution_id' => $inst->id,
            'student_id' => $qualification->student_number,
            'student_id_normalized' => LearnerRecordNormalizer::normalizeStudentId((string) $qualification->student_number),
            'program_of_study' => $title,
            'qualification_title_normalized' => LearnerRecordNormalizer::normalizeProgramTitle($title),
            'year_awarded' => 2024,
            'source_type' => LearnerRecordSourceType::Manual,
            'is_active' => true,
        ]);

        ProcessQualificationAutoVerificationJob::dispatchSync((int) $qualification->id);

        $qualification->refresh();
        $this->assertSame(VerificationState::AutoVerifiedPendingLevel2, $qualification->verification_state);
        $this->assertNotNull($qualification->learner_record_id);
        $this->assertSame('internal_learner_record', $qualification->verification_source);
        $this->assertNotNull($qualification->auto_verified_at);
        $this->assertSame(LearnerRecordMatchStatus::Matched, $qualification->auto_verification_status);
        $this->assertSame(70, (int) $qualification->auto_verification_confidence);
        $this->assertSame($title, (string) $qualification->verified_qualification_title);

        $this->assertSame(1, LearnerRecordMatchAttempt::query()->where('qualification_id', $qualification->id)->count());
        $this->assertSame(0, QualificationCertificate::query()->where('qualification_id', $qualification->id)->count());

        ProcessQualificationAutoVerificationJob::dispatchSync((int) $qualification->id);
        $this->assertSame(1, LearnerRecordMatchAttempt::query()->where('qualification_id', $qualification->id)->count());
    }

    public function test_job_can_auto_issue_certificate_when_enabled_and_payment_satisfied(): void
    {
        $this->seed(BillingCategoriesSeeder::class);
        $this->seed(QualificationTypesSeeder::class);
        $this->seed(FeeStructuresSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        config([
            'auto_verification.enabled' => true,
            'auto_verification.threshold' => 70,
            'auto_verification.auto_issue_enabled' => true,
        ]);

        Storage::fake('local');
        Mail::fake();

        $issuer = User::factory()->activated()->create(['applicant_type' => null]);
        $issuer->assignRole('Super Admin');

        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

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

        $type = QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();

        [$application, $qualification] = $this->makeAppWithQualification($applicant, $inst, $type);

        $title = $qualification->title_of_qualification;
        LearnerRecord::query()->create([
            'awarding_institution_id' => $inst->id,
            'student_id' => $qualification->student_number,
            'student_id_normalized' => LearnerRecordNormalizer::normalizeStudentId((string) $qualification->student_number),
            'program_of_study' => $title,
            'qualification_title_normalized' => LearnerRecordNormalizer::normalizeProgramTitle($title),
            'year_awarded' => 2024,
            'source_type' => LearnerRecordSourceType::Manual,
            'is_active' => true,
        ]);

        $satisfaction = app(ApplicationPaymentSatisfaction::class);
        $required = $satisfaction->outstandingCents($application);
        $this->assertGreaterThan(0, $required);

        Payment::query()->create([
            'application_id' => $application->id,
            'invoice_id' => null,
            'method' => PaymentMethod::Card,
            'status' => PaymentStatus::Confirmed,
            'currency' => 'ZMW',
            'amount_cents' => $required,
            'provider' => 'test',
            'confirmed_at' => now(),
        ]);

        $this->assertTrue($satisfaction->isSatisfied($application->fresh()));

        ProcessQualificationAutoVerificationJob::dispatchSync((int) $qualification->id);

        $qualification->refresh();
        $this->assertSame(VerificationState::CertificateIssued, $qualification->verification_state);
        $this->assertSame(1, QualificationCertificate::query()->where('qualification_id', $qualification->id)->count());
    }
}
