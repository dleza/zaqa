<?php

namespace Tests\Feature;

use App\Domain\Certificates\QualificationCertificateRevocationService;
use App\Domain\Fees\QualificationFeeResolver;
use App\Enums\ApplicationStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Qualification;
use App\Models\QualificationCertificate;
use App\Models\QualificationType;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdfWrapper;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\FeeStructuresSeeder;
use Database\Seeders\QualificationTypesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class CertificateRevocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BillingCategoriesSeeder::class);
        $this->seed(QualificationTypesSeeder::class);
        $this->seed(FeeStructuresSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * @return array{Application, Qualification, User}
     */
    private function eligiblePaidApprovedQualification(): array
    {
        $type = QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();
        $applicant = User::factory()->activated()->create([
            'applicant_type' => 'individual',
            'email' => 'cert-revoke-'.Str::lower((string) Str::ulid()).'@example.test',
        ]);

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-VER-'.random_int(10000, 99999),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Approved,
            'verification_state' => VerificationState::ApprovedForCertificate,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
            'approved_at' => now(),
        ]);

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'verification_reference_number' => 'ZAQA-Q-'.Str::upper((string) Str::ulid()),
            'awarding_institution_name' => 'Test Institution',
            'qualification_holder_name' => 'Jane Holder',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '999999/99/9',
            'title_of_qualification' => $type->name,
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'verification_state' => VerificationState::ApprovedForCertificate,
        ]);

        $application->refresh()->load('qualifications');
        $required = app(QualificationFeeResolver::class)->totalVerificationFeesCents($application);

        Payment::query()->create([
            'application_id' => $application->id,
            'method' => PaymentMethod::Card,
            'status' => PaymentStatus::Confirmed,
            'currency' => 'ZMW',
            'amount_cents' => $required,
            'provider' => 'test',
            'confirmed_at' => now(),
        ]);

        return [$application, $qualification, $applicant];
    }

    private function makeLevel2Officer(): User
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->assignRole('Verification Officer Level 2');

        return $user;
    }

    private function makeSuperAdmin(): User
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->assignRole('Super Admin');

        return $user;
    }

    private function mockPdfLoadView(string $output = '%PDF-test-output'): void
    {
        $pdfMock = Mockery::mock(DomPdfWrapper::class);
        $pdfMock->shouldReceive('setPaper')->andReturnSelf();
        $pdfMock->shouldReceive('output')->andReturn($output);

        Pdf::shouldReceive('loadView')->andReturn($pdfMock);
    }

    /**
     * @return array{Qualification, QualificationCertificate}
     */
    private function issueCertificateForQualification(Qualification $qualification, User $issuer): array
    {
        Storage::fake('local');
        Mail::fake();
        $this->mockPdfLoadView();

        $this->actingAs($issuer)
            ->post(route('admin.verification.qualifications.issue_certificate', $qualification))
            ->assertRedirect();

        $qualification->refresh();
        $cert = QualificationCertificate::query()
            ->where('qualification_id', $qualification->id)
            ->where('status', QualificationCertificate::STATUS_ISSUED)
            ->firstOrFail();

        return [$qualification, $cert];
    }

    private function revokePayload(string $reason = 'Issued in error.', ?string $publicNote = 'Recalled by ZAQA.'): array
    {
        return [
            'revocation_reason' => $reason,
            'revocation_public_note' => $publicNote,
            'confirm' => true,
        ];
    }

    public function test_level2_can_revoke_active_certificate(): void
    {
        [, $qualification] = $this->eligiblePaidApprovedQualification();
        $level2 = $this->makeLevel2Officer();
        [, $cert] = $this->issueCertificateForQualification($qualification, $level2);

        $this->actingAs($level2)
            ->post(route('admin.certificates.revoke', $cert), $this->revokePayload())
            ->assertRedirect();

        $cert->refresh();
        $this->assertSame(QualificationCertificate::STATUS_REVOKED, $cert->status);
        $this->assertNotNull($cert->revoked_at);
        $this->assertSame($level2->id, $cert->revoked_by_user_id);
        $this->assertSame('Issued in error.', $cert->revocation_reason);
        $this->assertSame('Recalled by ZAQA.', $cert->revocation_public_note);
    }

    public function test_super_admin_can_revoke_active_certificate(): void
    {
        [, $qualification] = $this->eligiblePaidApprovedQualification();
        $admin = $this->makeSuperAdmin();
        [, $cert] = $this->issueCertificateForQualification($qualification, $admin);

        $this->actingAs($admin)
            ->post(route('admin.certificates.revoke', $cert), $this->revokePayload())
            ->assertRedirect();

        $this->assertSame(QualificationCertificate::STATUS_REVOKED, $cert->fresh()->status);
    }

    public function test_level1_cannot_revoke_certificate(): void
    {
        [, $qualification] = $this->eligiblePaidApprovedQualification();
        $level2 = $this->makeLevel2Officer();
        [, $cert] = $this->issueCertificateForQualification($qualification, $level2);

        $level1 = User::factory()->activated()->create(['applicant_type' => null]);
        $level1->assignRole('Verification Officer Level 1');

        $this->actingAs($level1)
            ->post(route('admin.certificates.revoke', $cert), $this->revokePayload())
            ->assertForbidden();
    }

    public function test_applicant_cannot_revoke_certificate(): void
    {
        [$application, $qualification] = $this->eligiblePaidApprovedQualification();
        $level2 = $this->makeLevel2Officer();
        [, $cert] = $this->issueCertificateForQualification($qualification, $level2);

        $applicant = User::query()->findOrFail($application->applicant_user_id);

        $this->actingAs($applicant)
            ->post(route('admin.certificates.revoke', $cert), $this->revokePayload())
            ->assertForbidden();
    }

    public function test_revocation_requires_reason(): void
    {
        [, $qualification] = $this->eligiblePaidApprovedQualification();
        $level2 = $this->makeLevel2Officer();
        [, $cert] = $this->issueCertificateForQualification($qualification, $level2);

        $this->actingAs($level2)
            ->post(route('admin.certificates.revoke', $cert), [
                'revocation_reason' => '',
                'confirm' => true,
            ])
            ->assertSessionHasErrors('revocation_reason');

        $this->assertSame(QualificationCertificate::STATUS_ISSUED, $cert->fresh()->status);
    }

    public function test_only_active_certificates_can_be_revoked(): void
    {
        [, $qualification] = $this->eligiblePaidApprovedQualification();
        $level2 = $this->makeLevel2Officer();
        [, $cert] = $this->issueCertificateForQualification($qualification, $level2);

        app(QualificationCertificateRevocationService::class)->revoke(
            $cert,
            $level2,
            'First revocation.',
        );

        $this->actingAs($level2)
            ->post(route('admin.certificates.revoke', $cert->fresh()), $this->revokePayload('Again.'))
            ->assertSessionHasErrors('certificate');
    }

    public function test_revocation_records_audit_log(): void
    {
        [, $qualification] = $this->eligiblePaidApprovedQualification();
        $level2 = $this->makeLevel2Officer();
        [, $cert] = $this->issueCertificateForQualification($qualification, $level2);

        $this->actingAs($level2)
            ->post(route('admin.certificates.revoke', $cert), $this->revokePayload())
            ->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'certificates.qualification_revoked',
            'entity_type' => QualificationCertificate::class,
            'entity_id' => $cert->id,
        ]);
    }

    public function test_revoked_certificate_public_page_shows_recalled_message_without_internal_reason(): void
    {
        [, $qualification] = $this->eligiblePaidApprovedQualification();
        $level2 = $this->makeLevel2Officer();
        [, $cert] = $this->issueCertificateForQualification($qualification, $level2);

        app(QualificationCertificateRevocationService::class)->revoke(
            $cert,
            $level2,
            'Internal-only mistake details.',
            'Public recall notice.',
        );

        $this->get(route('certificates.verify', ['token' => $cert->verification_token]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Certificates/Verify')
                ->where('verification.status', QualificationCertificate::STATUS_REVOKED)
                ->where('verification.revocation_public_note', 'Public recall notice.')
                ->where('verification.message', 'This certificate has been recalled by the Zambia Qualifications Authority and is no longer valid.')
                ->missing('verification.revocation_reason'));
    }

    public function test_level2_can_issue_new_certificate_after_revocation(): void
    {
        [, $qualification] = $this->eligiblePaidApprovedQualification();
        $level2 = $this->makeLevel2Officer();
        [, $cert] = $this->issueCertificateForQualification($qualification, $level2);
        $oldToken = $cert->verification_token;
        $oldNumber = $cert->certificate_number;

        app(QualificationCertificateRevocationService::class)->revoke($cert, $level2, 'Issued in error.');

        Storage::fake('local');
        Mail::fake();
        $this->mockPdfLoadView();

        $this->actingAs($level2)
            ->post(route('admin.verification.qualifications.issue_certificate', $qualification))
            ->assertRedirect();

        $qualification->refresh();
        $this->assertSame(VerificationState::CertificateIssued, $qualification->verification_state);

        $newCert = QualificationCertificate::query()
            ->where('qualification_id', $qualification->id)
            ->where('status', QualificationCertificate::STATUS_ISSUED)
            ->firstOrFail();

        $this->assertNotSame($oldNumber, $newCert->certificate_number);
        $this->assertNotSame($oldToken, $newCert->verification_token);
        $this->assertSame($cert->id, $newCert->replaces_certificate_id);

        $this->assertSame(QualificationCertificate::STATUS_REVOKED, $cert->fresh()->status);

        $this->get(route('certificates.verify', ['token' => $oldToken]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('verification.status', QualificationCertificate::STATUS_REVOKED));

        $this->get(route('certificates.verify', ['token' => $newCert->verification_token]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('verification.status', QualificationCertificate::STATUS_ISSUED));
    }

    public function test_qualification_review_page_includes_certificate_history(): void
    {
        [, $qualification] = $this->eligiblePaidApprovedQualification();
        $level2 = $this->makeLevel2Officer();
        [, $cert] = $this->issueCertificateForQualification($qualification, $level2);

        $this->actingAs($level2)
            ->get(route('admin.verification.qualifications.show', $qualification))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('qualification.certificate_history.active.certificate_number', $cert->certificate_number)
                ->where('qualification.certificate_history.active.revoke_url', route('admin.certificates.revoke', $cert))
                ->where('can.revoke_certificate', true));
    }

    public function test_issue_button_available_after_revocation_when_no_active_certificate(): void
    {
        [, $qualification] = $this->eligiblePaidApprovedQualification();
        $level2 = $this->makeLevel2Officer();
        [, $cert] = $this->issueCertificateForQualification($qualification, $level2);

        app(QualificationCertificateRevocationService::class)->revoke($cert, $level2, 'Issued in error.');

        $this->actingAs($level2)
            ->get(route('admin.verification.qualifications.show', $qualification))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('qualification.can_issue_cveq_certificate', true)
                ->where('qualification.certificate_history.active', null)
                ->has('qualification.certificate_history.revoked', 1));
    }

    public function test_registry_lists_revoked_certificate_with_revoke_metadata(): void
    {
        [, $qualification] = $this->eligiblePaidApprovedQualification();
        $level2 = $this->makeLevel2Officer();
        [, $cert] = $this->issueCertificateForQualification($qualification, $level2);

        app(QualificationCertificateRevocationService::class)->revoke($cert, $level2, 'Issued in error.');

        $this->actingAs($level2)
            ->get(route('admin.certificates.index', ['status' => QualificationCertificate::STATUS_REVOKED]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('certificates.data', 1)
                ->where('certificates.data.0.status', QualificationCertificate::STATUS_REVOKED)
                ->has('certificates.data.0.show_url'));

        $this->actingAs($level2)
            ->get(route('admin.certificates.show', ['qualificationCertificate' => $cert->fresh()]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('certificate.status', QualificationCertificate::STATUS_REVOKED)
                ->where('certificate.revoked_by_name', $level2->name)
                ->where('certificate.revocation_reason', 'Issued in error.')
                ->where('certificate.revoke_url', null));
    }

    public function test_issue_after_revocation_records_audit_event(): void
    {
        [, $qualification] = $this->eligiblePaidApprovedQualification();
        $level2 = $this->makeLevel2Officer();
        [, $cert] = $this->issueCertificateForQualification($qualification, $level2);

        app(QualificationCertificateRevocationService::class)->revoke($cert, $level2, 'Issued in error.');

        Storage::fake('local');
        Mail::fake();
        $this->mockPdfLoadView();

        $this->actingAs($level2)
            ->post(route('admin.verification.qualifications.issue_certificate', $qualification))
            ->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'certificates.qualification_issued_after_revocation',
        ]);
    }
}
