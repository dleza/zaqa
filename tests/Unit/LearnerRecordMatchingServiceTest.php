<?php

namespace Tests\Unit;

use App\Domain\LearnerRecords\LearnerRecordMatchingService;
use App\Enums\LearnerRecordMatchStatus;
use App\Enums\LearnerRecordSourceType;
use App\Models\Application;
use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\LearnerRecord;
use App\Models\Qualification;
use App\Models\User;
use App\Support\Normalization\LearnerRecordNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LearnerRecordMatchingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_matches_by_student_id_institution_year_and_title_at_threshold(): void
    {
        config(['auto_verification.threshold' => 70]);

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

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-VER-'.rand(1000, 9999),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => 'submitted',
            'verification_state' => 'awaiting_auto_verification',
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
        ]);

        $title = 'Diploma in Testing';

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_id' => $inst->id,
            'awarding_institution_name' => $inst->name,
            'qualification_holder_name' => 'John Doe',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'student_number' => 'STU-001',
            'certificate_number' => null,
            'examination_number' => null,
            'title_of_qualification' => $title,
            'award_date' => '2024-01-10',
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'verification_state' => 'awaiting_auto_verification',
            'transcript_required' => false,
        ]);

        $studentNorm = LearnerRecordNormalizer::normalizeStudentId('STU-001');
        $titleNorm = LearnerRecordNormalizer::normalizeProgramTitle($title);

        $record = LearnerRecord::query()->create([
            'awarding_institution_id' => $inst->id,
            'student_id' => 'STU-001',
            'student_id_normalized' => $studentNorm,
            'certificate_no' => null,
            'certificate_no_normalized' => null,
            'program_of_study' => $title,
            'qualification_title_normalized' => $titleNorm,
            'year_awarded' => 2024,
            'name_normalized' => LearnerRecordNormalizer::normalizeNameParts('John', null, 'Doe'),
            'source_type' => LearnerRecordSourceType::Manual,
            'is_active' => true,
        ]);

        $result = app(LearnerRecordMatchingService::class)->match($qualification);

        $this->assertSame(LearnerRecordMatchStatus::Matched, $result->status);
        $this->assertGreaterThanOrEqual(70, $result->confidence);
        $this->assertSame((int) $record->id, (int) $result->learnerRecordId);
    }

    public function test_name_only_does_not_match(): void
    {
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-VER-'.rand(1000, 9999),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => 'submitted',
            'verification_state' => 'awaiting_auto_verification',
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
        ]);

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test',
            'qualification_holder_name' => 'John Doe',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'certificate_number' => null,
            'student_number' => null,
            'examination_number' => null,
            'title_of_qualification' => 'Some Title',
            'award_date' => '2024-01-10',
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'verification_state' => 'awaiting_auto_verification',
            'transcript_required' => false,
        ]);

        $result = app(LearnerRecordMatchingService::class)->match($qualification);

        $this->assertSame(LearnerRecordMatchStatus::NotFound, $result->status);
        $this->assertSame(0, $result->confidence);
    }

    public function test_ambiguous_when_multiple_top_candidates_within_margin(): void
    {
        config(['auto_verification.threshold' => 70]);

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

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-VER-'.rand(1000, 9999),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => 'submitted',
            'verification_state' => 'awaiting_auto_verification',
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
        ]);

        $title = 'Diploma in Testing';
        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_id' => $inst->id,
            'awarding_institution_name' => $inst->name,
            'qualification_holder_name' => 'John Doe',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'student_number' => 'STU-001',
            'title_of_qualification' => $title,
            'award_date' => '2024-01-10',
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'verification_state' => 'awaiting_auto_verification',
            'transcript_required' => false,
        ]);

        $studentNorm = LearnerRecordNormalizer::normalizeStudentId('STU-001');
        $titleNorm = LearnerRecordNormalizer::normalizeProgramTitle($title);
        $nameNorm = LearnerRecordNormalizer::normalizeNameParts('John', null, 'Doe');

        $r1 = LearnerRecord::query()->create([
            'awarding_institution_id' => $inst->id,
            'student_id' => 'STU-001',
            'student_id_normalized' => $studentNorm,
            'program_of_study' => $title,
            'qualification_title_normalized' => $titleNorm,
            'year_awarded' => 2024,
            'name_normalized' => $nameNorm,
            'source_type' => LearnerRecordSourceType::Manual,
            'is_active' => true,
        ]);

        $r2 = LearnerRecord::query()->create([
            'awarding_institution_id' => $inst->id,
            'student_id' => 'STU-001',
            'student_id_normalized' => $studentNorm,
            'program_of_study' => $title,
            'qualification_title_normalized' => $titleNorm,
            'year_awarded' => 2024,
            'name_normalized' => $nameNorm,
            'source_type' => LearnerRecordSourceType::Manual,
            'is_active' => true,
        ]);

        $result = app(LearnerRecordMatchingService::class)->match($qualification);

        $this->assertSame(LearnerRecordMatchStatus::Ambiguous, $result->status);
        $this->assertNull($result->learnerRecordId);
        $this->assertGreaterThanOrEqual(70, $result->confidence);
        $this->assertEqualsCanonicalizing([(int) $r1->id, (int) $r2->id], $result->candidateRecordIds);
    }

    public function test_confidence_is_capped_at_100(): void
    {
        config(['auto_verification.threshold' => 70]);

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

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-VER-'.rand(1000, 9999),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => 'submitted',
            'verification_state' => 'awaiting_auto_verification',
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
        ]);

        $title = 'Diploma in Testing';

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_id' => $inst->id,
            'awarding_institution_name' => $inst->name,
            'qualification_holder_name' => 'John Doe',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'student_number' => 'STU-001',
            'certificate_number' => 'CERT-001',
            'title_of_qualification' => $title,
            'award_date' => '2024-01-10',
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'verification_state' => 'awaiting_auto_verification',
            'transcript_required' => false,
        ]);

        LearnerRecord::query()->create([
            'awarding_institution_id' => $inst->id,
            'student_id' => 'STU-001',
            'student_id_normalized' => LearnerRecordNormalizer::normalizeStudentId('STU-001'),
            'certificate_no' => 'CERT-001',
            'certificate_no_normalized' => LearnerRecordNormalizer::normalizeCertificateNo('CERT-001'),
            'nrc_number' => '111111/11/1',
            'nrc_normalized' => LearnerRecordNormalizer::normalizeNrc('111111/11/1'),
            'program_of_study' => $title,
            'qualification_title_normalized' => LearnerRecordNormalizer::normalizeProgramTitle($title),
            'year_awarded' => 2024,
            'name_normalized' => LearnerRecordNormalizer::normalizeNameParts('John', null, 'Doe'),
            'source_type' => LearnerRecordSourceType::Manual,
            'is_active' => true,
        ]);

        $result = app(LearnerRecordMatchingService::class)->match($qualification);

        $this->assertLessThanOrEqual(100, $result->confidence);
    }
}
