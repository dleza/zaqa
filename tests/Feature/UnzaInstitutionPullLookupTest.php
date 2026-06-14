<?php

namespace Tests\Feature;

use App\Enums\InstitutionLearnerLookupStatus;
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
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class UnzaInstitutionPullLookupTest extends TestCase
{
    use RefreshDatabase;

    private const LOOKUP_URL = 'http://127.0.0.1:8001/api/zaqa/v1/learner-lookup';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'auto_verification.enabled' => true,
            'auto_verification.threshold' => 70,
            'auto_verification.auto_issue_enabled' => false,
        ]);
    }

    public function test_unza_integration_triggers_pull_lookup_and_sends_student_id(): void
    {
        $qualification = $this->makeUnzaQualification();

        Http::fake([
            self::LOOKUP_URL => Http::response([
                'found' => true,
                'source_reference' => 'verification_records:42',
                'confidence_hint' => 85,
                'record' => [
                    'student_id' => '2019001',
                    'certificate_no' => 'UNZA-2024-0001',
                    'first_name' => 'Martin',
                    'last_name' => 'Mwale',
                    'other_names' => null,
                    'nrc_number' => null,
                    'passport_no' => null,
                    'program_of_study' => 'Bachelor of Science',
                    'year_awarded' => 2024,
                    'award_date' => '2024-12-15',
                ],
            ], 200),
        ]);

        ProcessQualificationAutoVerificationJob::dispatchSync((int) $qualification->id);

        Http::assertSent(function (HttpRequest $request) {
            $data = $request->data();
            $this->assertSame('POST', $request->method());
            $this->assertSame('2019001', $data['student_id'] ?? null);
            $this->assertArrayHasKey('correlation_id', $data);
            $this->assertTrue($request->hasHeader('Authorization'));

            return true;
        });

        $qualification->refresh();
        $this->assertNotNull($qualification->institution_pull_lookup_attempted_at);
    }

    public function test_found_true_ingests_learner_record_and_reruns_auto_verification(): void
    {
        $qualification = $this->makeUnzaQualification();

        Http::fake([
            self::LOOKUP_URL => Http::response([
                'found' => true,
                'source_reference' => 'verification_records:42',
                'confidence_hint' => 85,
                'record' => [
                    'student_id' => '2019001',
                    'certificate_no' => 'UNZA-2024-0001',
                    'first_name' => 'Martin',
                    'last_name' => 'Mwale',
                    'other_names' => null,
                    'nrc_number' => null,
                    'passport_no' => null,
                    'program_of_study' => 'Bachelor of Science',
                    'year_awarded' => 2024,
                    'award_date' => '2024-12-15',
                ],
            ], 200),
        ]);

        $this->assertSame(0, LearnerRecord::query()->count());

        ProcessQualificationAutoVerificationJob::dispatchSync((int) $qualification->id);

        $qualification->refresh();
        $this->assertNotNull($qualification->learner_record_id);
        $this->assertSame('institution_api', (string) $qualification->verification_source);
        $this->assertSame(VerificationState::AutoVerifiedPendingLevel2, $qualification->verification_state);

        $record = LearnerRecord::query()->findOrFail((int) $qualification->learner_record_id);
        $this->assertSame(LearnerRecordSourceType::InstitutionApi, $record->source_type);
        $this->assertSame('2019001', $record->student_id);
    }

    public function test_found_false_falls_back_to_level_1_assignment(): void
    {
        $qualification = $this->makeUnzaQualification();

        Http::fake([
            self::LOOKUP_URL => Http::response([
                'found' => false,
                'source_reference' => null,
                'confidence_hint' => null,
                'record' => null,
            ], 200),
        ]);

        ProcessQualificationAutoVerificationJob::dispatchSync((int) $qualification->id);

        $qualification->refresh();
        $this->assertNotNull($qualification->institution_pull_lookup_attempted_at);
        $this->assertNull($qualification->learner_record_id);
        $this->assertSame(VerificationState::AwaitingAssignment, $qualification->verification_state);
        $this->assertSame(0, LearnerRecord::query()->count());
    }

    public function test_auth_failure_is_logged_safely_without_token_leak(): void
    {
        $qualification = $this->makeUnzaQualification();

        Http::fake([
            self::LOOKUP_URL => Http::response([
                'found' => false,
                'error' => ['code' => 'UNAUTHORIZED', 'message' => 'Invalid token'],
            ], 401),
        ]);

        ProcessQualificationAutoVerificationJob::dispatchSync((int) $qualification->id);

        $qualification->refresh();
        $this->assertSame(VerificationState::AwaitingAssignment, $qualification->verification_state);

        $log = InstitutionPullLookupLog::query()->where('qualification_id', $qualification->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame(InstitutionLearnerLookupStatus::Failed->value, $log->status);

        $serialized = json_encode([
            $log->request_payload,
            $log->response_payload,
            $log->error_message,
        ]);
        $this->assertIsString($serialized);
        $this->assertStringNotContainsString('unza-secret-token', $serialized);
    }

    public function test_timeout_failure_falls_back_to_level_1_and_is_logged(): void
    {
        $qualification = $this->makeUnzaQualification();

        Http::fake([
            self::LOOKUP_URL => fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection timed out'),
        ]);

        ProcessQualificationAutoVerificationJob::dispatchSync((int) $qualification->id);

        $qualification->refresh();
        $this->assertSame(VerificationState::AwaitingAssignment, $qualification->verification_state);

        $log = InstitutionPullLookupLog::query()->where('qualification_id', $qualification->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame(InstitutionLearnerLookupStatus::Timeout->value, $log->status);
        $this->assertStringContainsString('timeout', strtolower((string) $log->error_message));
    }

    public function test_examination_number_is_included_in_payload_when_available(): void
    {
        $qualification = $this->makeUnzaQualification(examinationNumber: 'EXAM-2024-001');

        Http::fake([
            self::LOOKUP_URL => Http::response([
                'found' => false,
                'source_reference' => null,
                'confidence_hint' => null,
                'record' => null,
            ], 200),
        ]);

        ProcessQualificationAutoVerificationJob::dispatchSync((int) $qualification->id);

        Http::assertSent(function (HttpRequest $request) {
            return ($request->data()['examination_number'] ?? null) === 'EXAM-2024-001';
        });
    }

    private function makeUnzaQualification(?string $examinationNumber = null): Qualification
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $country = Country::query()->firstOrCreate(
            ['iso_code' => 'ZMB'],
            ['name' => 'Zambia', 'is_active' => true, 'sort_order' => 1]
        );

        $inst = AwardingInstitution::query()->create([
            'country_id' => $country->id,
            'name' => 'University of Zambia',
            'consent_form_path' => null,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        InstitutionIntegration::query()->create([
            'awarding_institution_id' => (int) $inst->id,
            'is_active' => true,
            'supports_push' => false,
            'supports_pull' => true,
            'lookup_url' => self::LOOKUP_URL,
            'auth_type' => 'bearer_token',
            'credentials' => ['bearer_token' => 'unza-secret-token'],
            'request_method' => 'POST',
            'timeout_seconds' => 15,
            'retry_attempts' => 0,
            'driver' => 'generic_rest',
        ]);

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-UNZA-'.rand(1000, 9999),
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

        return Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_id' => $inst->id,
            'awarding_institution_name' => $inst->name,
            'qualification_holder_name' => 'Martin Mwale',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '123456/78/9',
            'student_number' => '2019001',
            'certificate_number' => 'UNZA-2024-0001',
            'examination_number' => $examinationNumber,
            'title_of_qualification' => 'Bachelor of Science',
            'award_date' => '2024-12-15',
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'verification_state' => VerificationState::AwaitingAutoVerification,
            'transcript_required' => false,
        ]);
    }
}
