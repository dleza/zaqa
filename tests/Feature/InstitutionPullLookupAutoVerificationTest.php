<?php

namespace Tests\Feature;

use App\Enums\LearnerRecordSourceType;
use App\Enums\VerificationState;
use App\Jobs\Verification\ProcessQualificationAutoVerificationJob;
use App\Models\Application;
use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\InstitutionIntegration;
use App\Models\InstitutionPullLookupLog;
use App\Models\LearnerRecord;
use App\Models\Qualification;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class InstitutionPullLookupAutoVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_auto_verification_dispatches_pull_lookup_when_internal_not_found_and_ingests_record(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        config([
            'auto_verification.enabled' => true,
            'auto_verification.threshold' => 70,
            'auto_verification.auto_issue_enabled' => false,
        ]);

        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $country = Country::query()->firstOrCreate(
            ['iso_code' => 'ZMB'],
            ['name' => 'Zambia', 'is_active' => true, 'sort_order' => 1]
        );

        $inst = AwardingInstitution::query()->create([
            'country_id' => $country->id,
            'name' => 'Pull University',
            'consent_form_path' => null,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        InstitutionIntegration::query()->create([
            'awarding_institution_id' => (int) $inst->id,
            'is_active' => true,
            'supports_push' => true,
            'supports_pull' => true,
            'lookup_url' => 'https://institution.test/lookup',
            'auth_type' => 'none',
            'credentials' => null,
            'request_method' => 'POST',
            'timeout_seconds' => 15,
            'retry_attempts' => 0,
            'driver' => 'generic_rest',
        ]);

        Http::fake([
            'https://institution.test/lookup' => Http::response([
                'found' => true,
                'source_reference' => 'INST-REF-1',
                'record' => [
                    'student_id' => 'STU-001',
                    'certificate_no' => null,
                    'first_name' => 'John',
                    'last_name' => 'Doe',
                    'other_names' => null,
                    'gender' => null,
                    'nrc_number' => '111111/11/1',
                    'passport_no' => null,
                    'program_of_study' => 'Diploma in Testing',
                    'year_awarded' => 2024,
                    'award_date' => '2024-01-10',
                ],
            ], 200),
        ]);

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-PULL-'.rand(1000, 9999),
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
            'title_of_qualification' => 'Diploma in Testing',
            'award_date' => '2024-01-10',
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'verification_state' => VerificationState::AwaitingAutoVerification,
            'transcript_required' => false,
        ]);

        // Internal DB has no records initially, so pull should be attempted and then ingestion should create one.
        $this->assertSame(0, LearnerRecord::query()->count());

        ProcessQualificationAutoVerificationJob::dispatchSync((int) $qualification->id);

        $qualification->refresh();
        $this->assertNotNull($qualification->institution_pull_lookup_attempted_at);
        $this->assertNotNull($qualification->learner_record_id);
        $this->assertSame('institution_api', (string) $qualification->verification_source);
        $this->assertSame(VerificationState::AutoVerifiedPendingLevel2, $qualification->verification_state);

        $record = LearnerRecord::query()->findOrFail((int) $qualification->learner_record_id);
        $this->assertSame(LearnerRecordSourceType::InstitutionApi, $record->source_type);

        $this->assertSame(1, InstitutionPullLookupLog::query()->where('qualification_id', $qualification->id)->count());
    }

    public function test_pull_lookup_is_not_used_when_internal_match_succeeds(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        config([
            'auto_verification.enabled' => true,
            'auto_verification.threshold' => 70,
            'auto_verification.auto_issue_enabled' => false,
        ]);

        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $country = Country::query()->firstOrCreate(
            ['iso_code' => 'ZMB'],
            ['name' => 'Zambia', 'is_active' => true, 'sort_order' => 1]
        );

        $inst = AwardingInstitution::query()->create([
            'country_id' => $country->id,
            'name' => 'Internal University',
            'consent_form_path' => null,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        InstitutionIntegration::query()->create([
            'awarding_institution_id' => (int) $inst->id,
            'is_active' => true,
            'supports_push' => true,
            'supports_pull' => true,
            'lookup_url' => 'https://institution.test/lookup',
            'auth_type' => 'none',
            'credentials' => null,
            'request_method' => 'POST',
            'timeout_seconds' => 15,
            'retry_attempts' => 0,
            'driver' => 'generic_rest',
        ]);

        Http::fake(); // Should not be called.

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-PULL-'.rand(1000, 9999),
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

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_id' => $inst->id,
            'awarding_institution_name' => $inst->name,
            'qualification_holder_name' => 'John Doe',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'student_number' => 'STU-010',
            'certificate_number' => null,
            'title_of_qualification' => 'Diploma in Testing',
            'award_date' => '2024-01-10',
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'verification_state' => VerificationState::AwaitingAutoVerification,
            'transcript_required' => false,
        ]);

        LearnerRecord::query()->create([
            'awarding_institution_id' => (int) $inst->id,
            'student_id' => 'STU-010',
            'student_id_normalized' => \App\Support\Normalization\LearnerRecordNormalizer::normalizeStudentId('STU-010'),
            'program_of_study' => 'Diploma in Testing',
            'qualification_title_normalized' => \App\Support\Normalization\LearnerRecordNormalizer::normalizeProgramTitle('Diploma in Testing'),
            'year_awarded' => 2024,
            'source_type' => LearnerRecordSourceType::Manual,
            'is_active' => true,
        ]);

        ProcessQualificationAutoVerificationJob::dispatchSync((int) $qualification->id);

        $qualification->refresh();
        $this->assertNull($qualification->institution_pull_lookup_dispatched_at);
        $this->assertNull($qualification->institution_pull_lookup_attempted_at);
        $this->assertSame(0, InstitutionPullLookupLog::query()->where('qualification_id', $qualification->id)->count());
    }
}
