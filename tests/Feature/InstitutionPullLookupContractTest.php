<?php

namespace Tests\Feature;

use App\Domain\InstitutionIntegrations\InstitutionPullLookupService;
use App\Enums\InstitutionLearnerLookupStatus;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\InstitutionIntegration;
use App\Models\InstitutionPullLookupLog;
use App\Models\LearnerRecord;
use App\Models\LearnerRecordSubmission;
use App\Models\Qualification;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class InstitutionPullLookupContractTest extends TestCase
{
    use RefreshDatabase;

    private function makeQualificationWithPullIntegration(): Qualification
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $country = Country::query()->firstOrCreate(
            ['iso_code' => 'ZMB'],
            ['name' => 'Zambia', 'is_active' => true, 'sort_order' => 1]
        );

        $inst = AwardingInstitution::query()->create([
            'country_id' => $country->id,
            'name' => 'Pull Contract University',
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

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-PULL-CONTRACT-'.rand(1000, 9999),
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
            'qualification_holder_name' => 'John Doe',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'student_number' => 'STU-001',
            'certificate_number' => 'CERT-123',
            'title_of_qualification' => 'Diploma in Testing',
            'award_date' => '2024-01-10',
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'verification_state' => VerificationState::AwaitingAutoVerification,
            'transcript_required' => false,
        ]);
    }

    public function test_valid_found_response_is_accepted_and_logs_are_sanitized(): void
    {
        $qualification = $this->makeQualificationWithPullIntegration();

        Http::fake([
            'https://institution.test/lookup' => Http::response([
                'found' => true,
                'source_reference' => 'INST-REF-0001',
                'confidence_hint' => 95,
                'record' => [
                    'student_id' => 'STU-001',
                    'certificate_no' => 'CERT-123',
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

        $result = app(InstitutionPullLookupService::class)->lookup($qualification);
        $this->assertSame(InstitutionLearnerLookupStatus::Found, $result->status);
        $this->assertTrue($result->found);
        $this->assertSame(95, $result->confidenceHint);
        $this->assertSame('INST-REF-0001', $result->sourceReference);

        $this->assertSame(0, LearnerRecord::query()->count());
        $this->assertSame(1, LearnerRecordSubmission::query()->count());
        $submission = LearnerRecordSubmission::query()->first();
        $this->assertSame('STU-001', $submission->student_id);
        $this->assertSame('pending', $submission->status?->value);

        $log = InstitutionPullLookupLog::query()->where('qualification_id', $qualification->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertIsArray($log->request_payload);
        $this->assertNotSame('111111/11/1', $log->request_payload['nrc_or_passport'] ?? null);

        $this->assertIsArray($log->response_payload);
        $masked = data_get($log->response_payload, 'record.nrc_number');
        $this->assertIsString($masked);
        $this->assertNotSame('111111/11/1', $masked);
        $this->assertStringContainsString('*', $masked);

        Http::assertSent(function (HttpRequest $request) use ($qualification) {
            $data = $request->data();
            $this->assertIsArray($data);
            $this->assertArrayHasKey('correlation_id', $data);
            $this->assertIsString($data['correlation_id']);
            $this->assertNotSame('', trim($data['correlation_id']));
            $this->assertTrue($request->hasHeader('X-Request-Id', $data['correlation_id']));

            $this->assertSame((string) ($qualification->verification_reference_number ?: ('qualification:'.$qualification->id)), $data['qualification_reference'] ?? null);
            $this->assertSame('STU-001', $data['student_id'] ?? null);
            $this->assertSame('CERT-123', $data['certificate_no'] ?? null);
            $this->assertSame('John', $data['first_name'] ?? null);
            $this->assertSame('Doe', $data['last_name'] ?? null);
            $this->assertSame('Diploma in Testing', $data['program_of_study'] ?? null);
            $this->assertSame(2024, $data['year_awarded'] ?? null);

            return true;
        });
    }

    public function test_valid_not_found_response_is_handled(): void
    {
        $qualification = $this->makeQualificationWithPullIntegration();

        Http::fake([
            'https://institution.test/lookup' => Http::response([
                'found' => false,
                'source_reference' => null,
                'confidence_hint' => null,
                'record' => null,
            ], 200),
        ]);

        $result = app(InstitutionPullLookupService::class)->lookup($qualification);
        $this->assertSame(InstitutionLearnerLookupStatus::NotFound, $result->status);
        $this->assertFalse($result->found);
        $this->assertSame(0, LearnerRecord::query()->count());
    }

    public function test_malformed_response_is_rejected_as_invalid_response(): void
    {
        $qualification = $this->makeQualificationWithPullIntegration();

        Http::fake([
            'https://institution.test/lookup' => Http::response([
                'record' => null,
            ], 200),
        ]);

        $result = app(InstitutionPullLookupService::class)->lookup($qualification);
        $this->assertSame(InstitutionLearnerLookupStatus::InvalidResponse, $result->status);
        $this->assertFalse($result->found);
        $this->assertSame(0, LearnerRecord::query()->count());
    }

    public function test_found_response_missing_required_record_fields_is_rejected(): void
    {
        $qualification = $this->makeQualificationWithPullIntegration();

        Http::fake([
            'https://institution.test/lookup' => Http::response([
                'found' => true,
                'source_reference' => 'INST-REF-0002',
                'confidence_hint' => 90,
                'record' => [
                    'student_id' => 'STU-001',
                    'certificate_no' => 'CERT-123',
                    'first_name' => 'John',
                    'last_name' => 'Doe',
                    'program_of_study' => '',
                    'year_awarded' => 2024,
                ],
            ], 200),
        ]);

        $result = app(InstitutionPullLookupService::class)->lookup($qualification);
        $this->assertSame(InstitutionLearnerLookupStatus::InvalidResponse, $result->status);
        $this->assertFalse($result->found);
        $this->assertSame(0, LearnerRecord::query()->count());
    }

    public function test_error_response_is_handled_safely(): void
    {
        $qualification = $this->makeQualificationWithPullIntegration();

        Http::fake([
            'https://institution.test/lookup' => Http::response([
                'found' => false,
                'error' => [
                    'code' => 'TEMPORARILY_UNAVAILABLE',
                    'message' => 'System is down for maintenance',
                ],
            ], 200),
        ]);

        $result = app(InstitutionPullLookupService::class)->lookup($qualification);
        $this->assertSame(InstitutionLearnerLookupStatus::Failed, $result->status);
        $this->assertFalse($result->found);
        $this->assertStringContainsString('TEMPORARILY_UNAVAILABLE', (string) $result->errorMessage);
        $this->assertSame(0, LearnerRecord::query()->count());
    }
}

