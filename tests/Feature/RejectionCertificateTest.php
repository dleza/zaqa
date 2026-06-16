<?php

namespace Tests\Feature;

use App\Domain\Certificates\QualificationCertificateRevocationService;
use App\Domain\Fees\QualificationFeeResolver;
use App\Domain\Verification\QualificationDecisionService;
use App\Enums\ApplicationStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\VerificationState;
use App\Models\Application;
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

class RejectionCertificateTest extends TestCase
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
    private function rejectedPaidQualification(): array
    {
        $type = QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();
        $applicant = User::factory()->activated()->create([
            'applicant_type' => 'individual',
            'email' => 'rej-cert-'.Str::lower((string) Str::ulid()).'@example.test',
        ]);

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-VER-'.random_int(10000, 99999),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::InProgress,
            'verification_state' => VerificationState::UnderLevel2Review,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
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
            'verification_state' => VerificationState::UnderLevel2Review,
        ]);

        app(QualificationDecisionService::class)->reject(
            $qualification,
            $this->makeLevel2Officer(),
            'Documents do not meet verification requirements.',
        );

        $qualification->refresh();

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

    private function mockPdfLoadView(string $output = '%PDF-test-output'): void
    {
        $pdfMock = Mockery::mock(DomPdfWrapper::class);
        $pdfMock->shouldReceive('setPaper')->andReturnSelf();
        $pdfMock->shouldReceive('output')->andReturn($output);

        Pdf::shouldReceive('loadView')->andReturn($pdfMock);
    }

    private function revokePayload(string $reason = 'Issued in error.'): array
    {
        return [
            'revocation_reason' => $reason,
            'revocation_public_note' => 'Recalled by ZAQA.',
            'confirm' => true,
        ];
    }

    public function test_level2_can_issue_rejection_certificate_for_rejected_qualification(): void
    {
        Storage::fake('local');
        [, $qualification] = $this->rejectedPaidQualification();
        $level2 = $this->makeLevel2Officer();
        $this->mockPdfLoadView();

        $this->actingAs($level2)
            ->post(route('admin.verification.qualifications.issue_rejection_certificate', $qualification))
            ->assertRedirect();

        $cert = QualificationCertificate::query()->where('qualification_id', $qualification->id)->firstOrFail();
        $this->assertSame(QualificationCertificate::TYPE_REJECTION, $cert->certificate_type);
        $this->assertSame(QualificationCertificate::STATUS_ISSUED, $cert->status);
        $this->assertStringStartsWith('ZAQA-REJ-', $cert->certificate_number);
        $this->assertNotEmpty($cert->verification_token);
        Storage::disk('local')->assertExists($cert->file_path);

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'certificates.rejection_issued',
            'entity_id' => $cert->id,
        ]);
    }

    public function test_level1_cannot_issue_rejection_certificate(): void
    {
        Storage::fake('local');
        [, $qualification] = $this->rejectedPaidQualification();
        $level1 = User::factory()->activated()->create(['applicant_type' => null]);
        $level1->assignRole('Verification Officer Level 1');

        $this->actingAs($level1)
            ->post(route('admin.verification.qualifications.issue_rejection_certificate', $qualification))
            ->assertForbidden();
    }

    public function test_cannot_issue_rejection_certificate_when_active_certificate_exists(): void
    {
        Storage::fake('local');
        [, $qualification] = $this->rejectedPaidQualification();
        $level2 = $this->makeLevel2Officer();
        $this->mockPdfLoadView();

        $this->actingAs($level2)
            ->post(route('admin.verification.qualifications.issue_rejection_certificate', $qualification))
            ->assertRedirect();

        $this->actingAs($level2)
            ->post(route('admin.verification.qualifications.issue_rejection_certificate', $qualification))
            ->assertSessionHasErrors('qualification');
    }

    public function test_active_rejection_certificate_public_page_shows_rejection_notice(): void
    {
        Storage::fake('local');
        [, $qualification] = $this->rejectedPaidQualification();
        $level2 = $this->makeLevel2Officer();
        $this->mockPdfLoadView();

        $this->actingAs($level2)
            ->post(route('admin.verification.qualifications.issue_rejection_certificate', $qualification))
            ->assertRedirect();

        $cert = QualificationCertificate::query()->where('qualification_id', $qualification->id)->firstOrFail();

        $this->get(route('certificates.verify', ['token' => $cert->verification_token]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('verification.status', QualificationCertificate::STATUS_ISSUED)
                ->where('verification.certificate_type', QualificationCertificate::TYPE_REJECTION)
                ->where('verification.status_label', 'Rejection notice issued')
                ->where('verification.message', 'This QR code confirms that ZAQA issued a rejection notice for the qualification shown below.'));
    }

    public function test_level2_can_revoke_active_rejection_certificate(): void
    {
        Storage::fake('local');
        [, $qualification] = $this->rejectedPaidQualification();
        $level2 = $this->makeLevel2Officer();
        $this->mockPdfLoadView();

        $this->actingAs($level2)
            ->post(route('admin.verification.qualifications.issue_rejection_certificate', $qualification))
            ->assertRedirect();

        $cert = QualificationCertificate::query()->where('qualification_id', $qualification->id)->firstOrFail();

        $this->actingAs($level2)
            ->post(route('admin.certificates.revoke', $cert), $this->revokePayload())
            ->assertRedirect();

        $cert->refresh();
        $this->assertSame(QualificationCertificate::STATUS_REVOKED, $cert->status);

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'certificates.rejection_revoked',
            'entity_id' => $cert->id,
        ]);
    }

    public function test_revoked_rejection_certificate_public_page_hides_internal_reason(): void
    {
        Storage::fake('local');
        [, $qualification] = $this->rejectedPaidQualification();
        $level2 = $this->makeLevel2Officer();
        $this->mockPdfLoadView();

        $this->actingAs($level2)
            ->post(route('admin.verification.qualifications.issue_rejection_certificate', $qualification))
            ->assertRedirect();

        $cert = QualificationCertificate::query()->where('qualification_id', $qualification->id)->firstOrFail();
        $token = $cert->verification_token;

        app(QualificationCertificateRevocationService::class)->revoke(
            $cert,
            $level2,
            'Internal mistake only.',
            'Public recall note.',
        );

        $this->get(route('certificates.verify', ['token' => $token]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('verification.status', QualificationCertificate::STATUS_REVOKED)
                ->where('verification.certificate_type', QualificationCertificate::TYPE_REJECTION)
                ->where('verification.revocation_public_note', 'Public recall note.')
                ->missing('verification.revocation_reason'));
    }

    public function test_new_rejection_certificate_can_be_issued_after_revocation(): void
    {
        Storage::fake('local');
        [, $qualification] = $this->rejectedPaidQualification();
        $level2 = $this->makeLevel2Officer();
        $this->mockPdfLoadView();

        $this->actingAs($level2)
            ->post(route('admin.verification.qualifications.issue_rejection_certificate', $qualification))
            ->assertRedirect();

        $first = QualificationCertificate::query()->where('qualification_id', $qualification->id)->firstOrFail();
        $oldToken = $first->verification_token;

        app(QualificationCertificateRevocationService::class)->revoke($first, $level2, 'Issued in error.');

        Mail::fake();
        $this->mockPdfLoadView();

        $this->actingAs($level2)
            ->post(route('admin.verification.qualifications.issue_rejection_certificate', $qualification))
            ->assertRedirect();

        $new = QualificationCertificate::query()
            ->where('qualification_id', $qualification->id)
            ->where('status', QualificationCertificate::STATUS_ISSUED)
            ->firstOrFail();

        $this->assertNotSame($first->certificate_number, $new->certificate_number);
        $this->assertNotSame($oldToken, $new->verification_token);
        $this->assertSame($first->id, $new->replaces_certificate_id);
        $this->assertSame(QualificationCertificate::STATUS_REVOKED, $first->fresh()->status);

        $this->get(route('certificates.verify', ['token' => $oldToken]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('verification.status', QualificationCertificate::STATUS_REVOKED));

        $this->get(route('certificates.verify', ['token' => $new->verification_token]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('verification.status', QualificationCertificate::STATUS_ISSUED));
    }

    public function test_registry_lists_rejection_certificate_with_type_filter(): void
    {
        Storage::fake('local');
        [, $qualification] = $this->rejectedPaidQualification();
        $level2 = $this->makeLevel2Officer();
        $this->mockPdfLoadView();

        $this->actingAs($level2)
            ->post(route('admin.verification.qualifications.issue_rejection_certificate', $qualification))
            ->assertRedirect();

        $cert = QualificationCertificate::query()->where('qualification_id', $qualification->id)->firstOrFail();

        $this->actingAs($level2)
            ->get(route('admin.certificates.index', ['type' => QualificationCertificate::TYPE_REJECTION]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('certificates.data', 1)
                ->where('certificates.data.0.certificate_type', QualificationCertificate::TYPE_REJECTION)
                ->where('certificates.data.0.id', $cert->id));
    }

    public function test_verification_certificate_can_be_issued_after_revoked_rejection_when_qualification_approved(): void
    {
        Storage::fake('local');
        [, $qualification] = $this->rejectedPaidQualification();
        $level2 = $this->makeLevel2Officer();
        $this->mockPdfLoadView();

        $this->actingAs($level2)
            ->post(route('admin.verification.qualifications.issue_rejection_certificate', $qualification))
            ->assertRedirect();

        $rejection = QualificationCertificate::query()->where('qualification_id', $qualification->id)->firstOrFail();
        app(QualificationCertificateRevocationService::class)->revoke($rejection, $level2, 'Decision reversed.');

        $qualification->forceFill(['verification_state' => VerificationState::ApprovedForCertificate])->save();

        Mail::fake();
        $this->mockPdfLoadView();

        $this->actingAs($level2)
            ->post(route('admin.verification.qualifications.issue_certificate', $qualification))
            ->assertRedirect();

        $verification = QualificationCertificate::query()
            ->where('qualification_id', $qualification->id)
            ->where('status', QualificationCertificate::STATUS_ISSUED)
            ->firstOrFail();

        $this->assertSame(QualificationCertificate::TYPE_VERIFICATION, $verification->certificate_type);
        $this->assertStringStartsWith('ZAQA-CVEQ-', $verification->certificate_number);
        $this->assertSame($rejection->id, $verification->replaces_certificate_id);
        $this->assertSame(QualificationCertificate::STATUS_REVOKED, $rejection->fresh()->status);
    }
}
