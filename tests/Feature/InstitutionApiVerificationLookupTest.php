<?php

namespace Tests\Feature;

use App\Enums\ApplicantType;
use App\Enums\ApplicationStatus;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\InstitutionApiClient;
use App\Models\Qualification;
use App\Models\QualificationCertificate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class InstitutionApiVerificationLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config([
            'certificates.verify_url_base' => 'https://verify.example.test/certificates',
            'institution_api.rate_limit_per_minute' => 60,
        ]);
    }

    private function makeInstitution(string $name): AwardingInstitution
    {
        $country = Country::query()->firstOrCreate(
            ['iso_code' => 'ZMB'],
            ['name' => 'Zambia', 'is_active' => true, 'sort_order' => 0]
        );

        return AwardingInstitution::query()->create([
            'country_id' => (int) $country->id,
            'name' => $name,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    private function makeClient(AwardingInstitution $institution, array $scopes = ['verification-records:lookup']): InstitutionApiClient
    {
        return InstitutionApiClient::query()->create([
            'awarding_institution_id' => (int) $institution->id,
            'name' => 'Lookup API Client',
            'scopes' => $scopes,
            'is_active' => true,
        ]);
    }

    /**
     * @return array{Application, Qualification, AwardingInstitution}
     */
    private function seedRecordForInstitution(AwardingInstitution $institution): array
    {
        $applicant = User::factory()->activated()->create(['applicant_type' => ApplicantType::Individual]);

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => '2026-000245',
            'applicant_user_id' => $applicant->id,
            'applicant_type' => ApplicantType::Individual,
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Submitted,
            'verification_state' => VerificationState::CertificateIssued,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
        ]);

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_id' => $institution->id,
            'awarding_institution_name' => $institution->name,
            'verification_reference_number' => '2026-000245-01',
            'qualification_holder_name' => 'Martin Mwale',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '123456/78/1',
            'title_of_qualification' => 'Bachelor of Science in Information Systems and Technology',
            'award_date' => '2020-11-06',
            'qualification_type' => 'L6',
            'verification_state' => VerificationState::CertificateIssued,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ]);

        QualificationCertificate::query()->create([
            'qualification_id' => $qualification->id,
            'application_id' => $application->id,
            'certificate_number' => 'ZAQA-CVEQ-2026-000008',
            'zaqa_reference_number' => $qualification->verification_reference_number,
            'verification_token' => 'api-lookup-token',
            'file_path' => 'certificates/test.pdf',
            'issued_by_user_id' => User::factory()->activated()->create()->id,
            'issued_at' => now()->subDays(2),
            'status' => QualificationCertificate::STATUS_ISSUED,
            'certificate_type' => QualificationCertificate::TYPE_VERIFICATION,
        ]);

        return [$application, $qualification, $institution];
    }

    public function test_unauthenticated_api_request_is_rejected(): void
    {
        $this->getJson('/api/institution/v1/verification-records/lookup?qualification_reference=2026-000245-01')
            ->assertStatus(401);
    }

    public function test_invalid_token_is_rejected(): void
    {
        $this->getJson('/api/institution/v1/verification-records/lookup?qualification_reference=2026-000245-01', [
            'Authorization' => 'Bearer invalid-token',
        ])->assertStatus(401);
    }

    public function test_active_integrated_institution_can_search_by_application_reference(): void
    {
        $institution = $this->makeInstitution('University of Lusaka');
        [$application] = $this->seedRecordForInstitution($institution);
        $client = $this->makeClient($institution);
        $token = $client->createToken('t', ['verification-records:lookup'])->plainTextToken;

        $this->getJson('/api/institution/v1/verification-records/lookup?application_reference='.$application->application_number, [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('searched_by', 'application_reference')
            ->assertJsonPath('application_reference', $application->application_number);
    }

    public function test_active_integrated_institution_can_search_by_qualification_reference(): void
    {
        $institution = $this->makeInstitution('University of Lusaka');
        [, $qualification] = $this->seedRecordForInstitution($institution);
        $client = $this->makeClient($institution);
        $token = $client->createToken('t', ['verification-records:lookup'])->plainTextToken;

        $this->getJson('/api/institution/v1/verification-records/lookup?qualification_reference='.$qualification->verification_reference_number, [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('qualification.holder_name', 'Martin Mwale')
            ->assertJsonPath('certificate.number', 'ZAQA-CVEQ-2026-000008')
            ->assertJsonPath('certificate.public_verification_url', 'https://verify.example.test/certificates/api-lookup-token');
    }

    public function test_both_fields_filled_returns_422(): void
    {
        $institution = $this->makeInstitution('University of Lusaka');
        $client = $this->makeClient($institution);
        $token = $client->createToken('t', ['verification-records:lookup'])->plainTextToken;

        $this->getJson('/api/institution/v1/verification-records/lookup?application_reference=2026-000245&qualification_reference=2026-000245-01', [
            'Authorization' => 'Bearer '.$token,
        ])->assertStatus(422);
    }

    public function test_not_found_returns_found_false(): void
    {
        $institution = $this->makeInstitution('University of Lusaka');
        $client = $this->makeClient($institution);
        $token = $client->createToken('t', ['verification-records:lookup'])->plainTextToken;

        $this->getJson('/api/institution/v1/verification-records/lookup?qualification_reference=2099-999999-99', [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertStatus(404)
            ->assertJsonPath('found', false);
    }

    public function test_revoked_certificate_returns_revoked_true_without_internal_reason(): void
    {
        $institution = $this->makeInstitution('University of Lusaka');
        [, $qualification] = $this->seedRecordForInstitution($institution);

        QualificationCertificate::query()
            ->where('qualification_id', $qualification->id)
            ->update([
                'status' => QualificationCertificate::STATUS_REVOKED,
                'revoked_at' => now(),
                'revocation_reason' => 'Sensitive internal reason',
            ]);

        $client = $this->makeClient($institution);
        $token = $client->createToken('t', ['verification-records:lookup'])->plainTextToken;

        $response = $this->getJson('/api/institution/v1/verification-records/lookup?qualification_reference='.$qualification->verification_reference_number, [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertOk()
            ->assertJsonPath('certificate.revoked', true)
            ->assertJsonPath('status', 'certificate_revoked');

        $body = json_encode($response->json());
        $this->assertIsString($body);
        $this->assertStringNotContainsString('Sensitive internal reason', $body);
        $this->assertStringNotContainsString('revocation_reason', $body);
    }

    public function test_api_response_does_not_include_nrc_or_internal_fields(): void
    {
        $institution = $this->makeInstitution('University of Lusaka');
        [, $qualification] = $this->seedRecordForInstitution($institution);
        $client = $this->makeClient($institution);
        $token = $client->createToken('t', ['verification-records:lookup'])->plainTextToken;

        $response = $this->getJson('/api/institution/v1/verification-records/lookup?qualification_reference='.$qualification->verification_reference_number, [
            'Authorization' => 'Bearer '.$token,
        ])->assertOk();

        $body = json_encode($response->json());
        $this->assertIsString($body);
        $this->assertStringNotContainsString('123456/78/1', $body);
        $this->assertStringNotContainsString('nrc', strtolower($body));
        $this->assertStringNotContainsString('passport', strtolower($body));
        $this->assertStringNotContainsString('assigned_verifier', $body);
    }

    public function test_api_is_rate_limited(): void
    {
        config(['institution_api.rate_limit_per_minute' => 2]);

        $institution = $this->makeInstitution('University of Lusaka');
        $client = $this->makeClient($institution);
        $newToken = $client->createToken('t', ['verification-records:lookup']);
        $token = $newToken->plainTextToken;
        $tokenId = (int) $newToken->accessToken->getKey();
        $headers = ['Authorization' => 'Bearer '.$token];

        RateLimiter::clear('institution-api:'.$client->id.':'.$tokenId);

        $this->getJson('/api/institution/v1/verification-records/lookup?qualification_reference=2099-999999-99', $headers)->assertStatus(404);
        $this->getJson('/api/institution/v1/verification-records/lookup?qualification_reference=2099-999999-98', $headers)->assertStatus(404);
        $this->getJson('/api/institution/v1/verification-records/lookup?qualification_reference=2099-999999-97', $headers)->assertStatus(429);
    }

    public function test_name_search_does_not_match_api_lookup(): void
    {
        $institution = $this->makeInstitution('University of Lusaka');
        $this->seedRecordForInstitution($institution);
        $client = $this->makeClient($institution);
        $token = $client->createToken('t', ['verification-records:lookup'])->plainTextToken;

        $this->getJson('/api/institution/v1/verification-records/lookup?qualification_reference=Martin%20Mwale', [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertStatus(404)
            ->assertJsonPath('found', false);
    }

    public function test_api_restricts_results_to_calling_awarding_institution(): void
    {
        $owner = $this->makeInstitution('University of Lusaka');
        $other = $this->makeInstitution('Other University');
        [, $qualification] = $this->seedRecordForInstitution($owner);

        $client = $this->makeClient($other);
        $token = $client->createToken('t', ['verification-records:lookup'])->plainTextToken;

        $this->getJson('/api/institution/v1/verification-records/lookup?qualification_reference='.$qualification->verification_reference_number, [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertStatus(404)
            ->assertJsonPath('found', false);
    }

    public function test_api_lookup_is_audited(): void
    {
        $institution = $this->makeInstitution('University of Lusaka');
        [, $qualification] = $this->seedRecordForInstitution($institution);
        $client = $this->makeClient($institution);
        $token = $client->createToken('t', ['verification-records:lookup'])->plainTextToken;

        $this->getJson('/api/institution/v1/verification-records/lookup?qualification_reference='.$qualification->verification_reference_number, [
            'Authorization' => 'Bearer '.$token,
        ])->assertOk();

        $this->assertTrue(
            AuditLog::query()
                ->where('event_type', 'integrated_verification_lookup.performed')
                ->where('entity_type', InstitutionApiClient::class)
                ->where('entity_id', $client->id)
                ->exists()
        );
    }

    public function test_token_without_lookup_ability_is_forbidden(): void
    {
        $institution = $this->makeInstitution('University of Lusaka');
        $client = $this->makeClient($institution, ['learner-records:read']);
        $token = $client->createToken('t', ['learner-records:read'])->plainTextToken;

        $this->getJson('/api/institution/v1/verification-records/lookup?qualification_reference=2026-000245-01', [
            'Authorization' => 'Bearer '.$token,
        ])->assertStatus(403);
    }
}
