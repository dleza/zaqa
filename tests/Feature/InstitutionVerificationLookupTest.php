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
use App\Models\InstitutionProfile;
use App\Models\Qualification;
use App\Models\QualificationCertificate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InstitutionVerificationLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['certificates.verify_url_base' => 'https://verify.example.test/certificates']);
    }

    private function makeInstitutionUser(): User
    {
        $user = User::factory()->activated()->create([
            'applicant_type' => ApplicantType::Institution,
            'name' => 'Lookup Institution Ltd',
        ]);

        InstitutionProfile::create([
            'user_id' => $user->id,
            'institution_name' => 'Lookup Institution Ltd',
            'email' => $user->email,
            'phone_primary' => $user->phone_primary,
            'tpin' => '1000000001',
        ]);

        return $user;
    }

    /**
     * @return array{Application, Qualification}
     */
    private function seedVerificationRecord(array $appOverrides = [], array $qualOverrides = []): array
    {
        $applicant = User::factory()->activated()->create(['applicant_type' => ApplicantType::Individual]);

        $application = Application::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'application_number' => '2026-000245',
            'applicant_user_id' => $applicant->id,
            'applicant_type' => ApplicantType::Individual,
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Submitted,
            'verification_state' => VerificationState::AwaitingAssignment,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
        ], $appOverrides));

        $qualification = Qualification::query()->create(array_merge([
            'application_id' => $application->id,
            'verification_reference_number' => '2026-000245-01',
            'awarding_institution_name' => 'University of Lusaka',
            'qualification_holder_name' => 'Martin Mwale',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '999999/99/9',
            'title_of_qualification' => 'Bachelor of Science in Information Systems and Technology',
            'award_date' => '2020-11-06',
            'qualification_type' => 'L6',
            'verification_state' => VerificationState::UnderLevel1Review,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ], $qualOverrides));

        return [$application, $qualification];
    }

    public function test_institution_user_can_access_lookup_page(): void
    {
        $user = $this->makeInstitutionUser();

        $this->actingAs($user)
            ->get(route('applicant.institution.verification_lookup'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Applicant/Institution/VerificationLookup'));
    }

    public function test_individual_applicant_cannot_access_lookup_page(): void
    {
        $user = User::factory()->activated()->create(['applicant_type' => ApplicantType::Individual]);

        $this->actingAs($user)
            ->get(route('applicant.institution.verification_lookup'))
            ->assertForbidden();
    }

    public function test_institution_user_can_search_by_application_reference(): void
    {
        [$application] = $this->seedVerificationRecord();
        $user = $this->makeInstitutionUser();

        $response = $this->actingAs($user)
            ->post(route('applicant.institution.verification_lookup.search'), [
                'reference_type' => 'application_reference',
                'reference' => $application->application_number,
            ]);

        $response->assertRedirect(route('applicant.institution.verification_lookup'))
            ->assertSessionHas('lookup_result');

        $result = $response->getSession()->get('lookup_result');
        $this->assertTrue($result['found']);
        $this->assertSame('application_reference', $result['searched_by']);
        $this->assertSame('2026-000245-01', $result['qualifications'][0]['qualification_reference']);
    }

    public function test_institution_user_can_search_by_qualification_reference(): void
    {
        [, $qualification] = $this->seedVerificationRecord();
        $user = $this->makeInstitutionUser();

        $response = $this->actingAs($user)
            ->post(route('applicant.institution.verification_lookup.search'), [
                'reference_type' => 'qualification_reference',
                'reference' => $qualification->verification_reference_number,
            ]);

        $response->assertRedirect()
            ->assertSessionHas('lookup_result');

        $result = $response->getSession()->get('lookup_result');
        $this->assertTrue($result['found']);
        $this->assertSame('qualification_reference', $result['searched_by']);
        $this->assertSame('Martin Mwale', $result['qualification']['holder_name']);
    }

    public function test_empty_reference_returns_validation_error(): void
    {
        $user = $this->makeInstitutionUser();

        $this->actingAs($user)
            ->from(route('applicant.institution.verification_lookup'))
            ->post(route('applicant.institution.verification_lookup.search'), [
                'reference_type' => 'application_reference',
                'reference' => '',
            ])
            ->assertRedirect(route('applicant.institution.verification_lookup'))
            ->assertSessionHasErrors(['reference']);
    }

    public function test_no_fields_filled_returns_validation_error(): void
    {
        $user = $this->makeInstitutionUser();

        $this->actingAs($user)
            ->from(route('applicant.institution.verification_lookup'))
            ->post(route('applicant.institution.verification_lookup.search'), [])
            ->assertRedirect(route('applicant.institution.verification_lookup'))
            ->assertSessionHasErrors(['application_reference']);
    }

    public function test_not_found_shows_friendly_result(): void
    {
        $user = $this->makeInstitutionUser();

        $response = $this->actingAs($user)
            ->post(route('applicant.institution.verification_lookup.search'), [
                'reference_type' => 'qualification_reference',
                'reference' => '2099-999999-99',
            ]);

        $response->assertRedirect()
            ->assertSessionHas('lookup_result');

        $result = $response->getSession()->get('lookup_result');
        $this->assertFalse($result['found']);
        $this->assertSame('not_found', $result['status']);
    }

    public function test_revoked_certificate_shows_recalled_message_without_internal_reason(): void
    {
        [, $qualification] = $this->seedVerificationRecord([
            'verification_state' => VerificationState::CertificateIssued,
        ], [
            'verification_state' => VerificationState::CertificateIssued,
        ]);

        QualificationCertificate::query()->create([
            'qualification_id' => $qualification->id,
            'application_id' => $qualification->application_id,
            'certificate_number' => 'CERT-2026-000008',
            'zaqa_reference_number' => $qualification->verification_reference_number,
            'verification_token' => 'revoked-token-abc',
            'file_path' => 'certificates/test.pdf',
            'issued_by_user_id' => User::factory()->activated()->create()->id,
            'issued_at' => now()->subDay(),
            'status' => QualificationCertificate::STATUS_REVOKED,
            'certificate_type' => QualificationCertificate::TYPE_VERIFICATION,
            'revoked_at' => now(),
            'revocation_reason' => 'Internal sensitive reason',
        ]);

        $user = $this->makeInstitutionUser();

        $response = $this->actingAs($user)
            ->post(route('applicant.institution.verification_lookup.search'), [
                'reference_type' => 'qualification_reference',
                'reference' => $qualification->verification_reference_number,
            ]);

        $response->assertRedirect()
            ->assertSessionHas('lookup_result');

        $result = $response->getSession()->get('lookup_result');
        $this->assertTrue($result['found']);
        $this->assertSame('certificate_revoked', $result['status']);
        $this->assertStringContainsString('no longer valid', strtolower($result['message']));
        $this->assertArrayNotHasKey('revocation_reason', $result);
        $this->assertArrayNotHasKey('nrc_passport_number', $result['qualification'] ?? []);
    }

    public function test_application_reference_with_multiple_qualifications_returns_list(): void
    {
        [$application] = $this->seedVerificationRecord();
        Qualification::query()->create([
            'application_id' => $application->id,
            'verification_reference_number' => '2026-000245-02',
            'awarding_institution_name' => 'Another College',
            'qualification_holder_name' => 'Second Holder',
            'nrc_passport_number' => '222222/22/2',
            'country_name_other' => 'Zambia',
            'title_of_qualification' => 'Diploma B',
            'award_date' => '2021-01-01',
            'qualification_type' => 'L6',
            'verification_state' => VerificationState::AwaitingAssignment,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ]);

        $user = $this->makeInstitutionUser();

        $response = $this->actingAs($user)
            ->post(route('applicant.institution.verification_lookup.search'), [
                'reference_type' => 'application_reference',
                'reference' => $application->application_number,
            ]);

        $response->assertRedirect()
            ->assertSessionHas('lookup_result');

        $result = $response->getSession()->get('lookup_result');
        $this->assertTrue($result['found']);
        $this->assertCount(2, $result['qualifications']);
    }

    public function test_institution_user_can_search_by_certificate_reference(): void
    {
        [, $qualification] = $this->seedVerificationRecord([
            'verification_state' => VerificationState::CertificateIssued,
        ], [
            'verification_state' => VerificationState::CertificateIssued,
        ]);

        QualificationCertificate::query()->create([
            'qualification_id' => $qualification->id,
            'application_id' => $qualification->application_id,
            'certificate_number' => 'CERT-2026-000008',
            'zaqa_reference_number' => $qualification->verification_reference_number,
            'verification_token' => 'cert-lookup-token',
            'file_path' => 'certificates/test.pdf',
            'issued_by_user_id' => User::factory()->activated()->create()->id,
            'issued_at' => now()->subDay(),
            'status' => QualificationCertificate::STATUS_ISSUED,
            'certificate_type' => QualificationCertificate::TYPE_VERIFICATION,
        ]);

        $user = $this->makeInstitutionUser();

        $response = $this->actingAs($user)
            ->post(route('applicant.institution.verification_lookup.search'), [
                'reference_type' => 'certificate_reference',
                'reference' => 'CERT-2026-000008',
            ]);

        $response->assertRedirect()
            ->assertSessionHas('lookup_result');

        $result = $response->getSession()->get('lookup_result');
        $this->assertTrue($result['found']);
        $this->assertSame('certificate_reference', $result['searched_by']);
        $this->assertSame('CERT-2026-000008', $result['certificate']['number']);
    }

    public function test_result_does_not_expose_internal_officer_audit_payment_or_document_data(): void
    {
        [, $qualification] = $this->seedVerificationRecord([], [
            'assigned_verifier_id' => User::factory()->activated()->create()->id,
        ]);

        $user = $this->makeInstitutionUser();

        $response = $this->actingAs($user)
            ->post(route('applicant.institution.verification_lookup.search'), [
                'reference_type' => 'qualification_reference',
                'reference' => $qualification->verification_reference_number,
            ]);

        $response->assertRedirect();

        $encoded = json_encode($response->getSession()->get('lookup_result'));
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('assigned_verifier', $encoded);
        $this->assertStringNotContainsString('999999/99/9', $encoded);
        $this->assertStringNotContainsString('payment', strtolower($encoded));
        $this->assertStringNotContainsString('audit', strtolower($encoded));
    }

    public function test_lookup_is_audited_for_institution_applicant(): void
    {
        [, $qualification] = $this->seedVerificationRecord();
        $user = $this->makeInstitutionUser();

        $this->actingAs($user)
            ->post(route('applicant.institution.verification_lookup.search'), [
                'reference_type' => 'qualification_reference',
                'reference' => $qualification->verification_reference_number,
            ]);

        $this->assertTrue(
            AuditLog::query()
                ->where('event_type', 'institution_verification_lookup.performed')
                ->where('actor_user_id', $user->id)
                ->exists()
        );
    }
}
