<?php

namespace Tests\Feature;

use App\Domain\Certificates\QualificationCertificateBulkIssueExcelService;
use App\Domain\Fees\QualificationFeeResolver;
use App\Enums\ApplicationStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\VerificationState;
use App\Mail\QualificationCertificateIssuedMail;
use App\Models\Application;
use App\Models\Payment;
use App\Models\Qualification;
use App\Models\QualificationCertificate;
use App\Models\QualificationSubjectResult;
use App\Models\QualificationType;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdfWrapper;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\FeeStructuresSeeder;
use Database\Seeders\QualificationTypesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class QualificationCertificateIssuanceTest extends TestCase
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
    private function eligiblePaidApprovedQualification(
        string $qualificationTypeCode = 'L6',
        array $qualificationOverrides = [],
        array $subjectResults = [],
    ): array
    {
        $type = QualificationType::query()->where('zqf_level_code', $qualificationTypeCode)->firstOrFail();
        $applicant = User::factory()->activated()->create([
            'applicant_type' => 'individual',
            'email' => 'app-cert-test-'.Str::lower((string) Str::ulid()).'@example.test',
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
        ] + $qualificationOverrides);

        foreach ($subjectResults as $index => $row) {
            QualificationSubjectResult::query()->create([
                'qualification_id' => $qualification->id,
                'certificate_subject_id' => $row['certificate_subject_id'] ?? null,
                'subject_name' => $row['subject_name'] ?? null,
                'grade' => $row['grade'] ?? null,
                'display_order' => $row['display_order'] ?? ($index + 1),
            ]);
        }

        $application->refresh()->load('qualifications');
        $required = app(QualificationFeeResolver::class)->totalVerificationFeesCents($application);
        self::assertGreaterThan(0, $required);

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

        return [$application, $qualification, $applicant];
    }

    private function makeCertificateAdmin(bool $superAdmin = false): User
    {
        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->givePermissionTo([
            'verification.pool.view',
            'verification.certificate.issue',
            'dashboard.view',
        ]);

        if ($superAdmin) {
            $admin->assignRole('Super Admin');
        }

        return $admin;
    }

    private function mockPdfLoadView(callable $assertion, string $output = '%PDF-test-output'): void
    {
        $pdfMock = Mockery::mock(DomPdfWrapper::class);
        $pdfMock->shouldReceive('setPaper')->once()->with('A4', 'portrait')->andReturnSelf();
        $pdfMock->shouldReceive('output')->once()->andReturn($output);

        Pdf::shouldReceive('loadView')
            ->once()
            ->withArgs(function (string $view, array $data) use ($assertion) {
                $assertion($view, $data);

                return true;
            })
            ->andReturn($pdfMock);
    }

    public function test_admin_can_issue_certificate_for_verified_paid_qualification(): void
    {
        Storage::fake('local');
        Mail::fake();

        [$application, $qualification, $applicant] = $this->eligiblePaidApprovedQualification();

        $admin = $this->makeCertificateAdmin();

        $response = $this->actingAs($admin)->post(
            route('admin.verification.qualifications.issue_certificate', $qualification),
            [],
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('qualification_certificates', [
            'qualification_id' => $qualification->id,
            'application_id' => $application->id,
            'status' => QualificationCertificate::STATUS_ISSUED,
        ]);

        $qualification->refresh();
        $this->assertSame(VerificationState::CertificateIssued, $qualification->verification_state);

        Mail::assertQueued(QualificationCertificateIssuedMail::class, function (QualificationCertificateIssuedMail $mail) use ($applicant) {
            return $mail->hasTo($applicant->email);
        });

        $cert = QualificationCertificate::query()->where('qualification_id', $qualification->id)->firstOrFail();
        Storage::disk('local')->assertExists($cert->file_path);
    }

    public function test_l1_uses_school_subjects_template(): void
    {
        Storage::fake('local');
        Mail::fake();

        [, $qualification] = $this->eligiblePaidApprovedQualification('L1', subjectResults: [
            ['subject_name' => 'Mathematics', 'grade' => '1'],
        ]);

        $this->mockPdfLoadView(function (string $view, array $data) {
            $this->assertSame('pdf.qualification-certificate-subjects', $view);
            $this->assertCount(1, $data['subject_results'] ?? []);
        });

        $this->actingAs($this->makeCertificateAdmin())
            ->post(route('admin.verification.qualifications.issue_certificate', $qualification))
            ->assertRedirect();
    }

    public function test_l2a_uses_school_subjects_template(): void
    {
        Storage::fake('local');
        Mail::fake();

        [, $qualification] = $this->eligiblePaidApprovedQualification('L2A', subjectResults: [
            ['subject_name' => 'English', 'grade' => '2'],
        ]);

        $this->mockPdfLoadView(fn (string $view, array $data) => $this->assertSame('pdf.qualification-certificate-subjects', $view));

        $this->actingAs($this->makeCertificateAdmin())
            ->post(route('admin.verification.qualifications.issue_certificate', $qualification))
            ->assertRedirect();
    }

    public function test_l2b_uses_school_subjects_template(): void
    {
        Storage::fake('local');
        Mail::fake();

        [, $qualification] = $this->eligiblePaidApprovedQualification('L2B', subjectResults: [
            ['subject_name' => 'Biology', 'grade' => 'A'],
        ]);

        $this->mockPdfLoadView(fn (string $view, array $data) => $this->assertSame('pdf.qualification-certificate-subjects', $view));

        $this->actingAs($this->makeCertificateAdmin())
            ->post(route('admin.verification.qualifications.issue_certificate', $qualification))
            ->assertRedirect();
    }

    public function test_non_school_qualification_uses_default_template(): void
    {
        Storage::fake('local');
        Mail::fake();

        [, $qualification] = $this->eligiblePaidApprovedQualification('L6');

        $this->mockPdfLoadView(fn (string $view, array $data) => $this->assertSame('pdf.qualification-certificate', $view));

        $this->actingAs($this->makeCertificateAdmin())
            ->post(route('admin.verification.qualifications.issue_certificate', $qualification))
            ->assertRedirect();
    }

    public function test_subject_rows_are_passed_to_subject_certificate_template_in_display_order(): void
    {
        Storage::fake('local');
        Mail::fake();

        [, $qualification] = $this->eligiblePaidApprovedQualification('L1', subjectResults: [
            ['subject_name' => 'English', 'grade' => '2', 'display_order' => 2],
            ['subject_name' => 'Mathematics', 'grade' => '1', 'display_order' => 1],
        ]);

        $this->mockPdfLoadView(function (string $view, array $data) {
            $this->assertSame('pdf.qualification-certificate-subjects', $view);
            $this->assertSame('Mathematics', $data['subject_results'][0]['subject_name'] ?? null);
            $this->assertSame('1', $data['subject_results'][0]['grade'] ?? null);
            $this->assertSame('English', $data['subject_results'][1]['subject_name'] ?? null);
        });

        $this->actingAs($this->makeCertificateAdmin())
            ->post(route('admin.verification.qualifications.issue_certificate', $qualification))
            ->assertRedirect();
    }

    public function test_level1_officer_cannot_issue_certificate(): void
    {
        [, $qualification] = $this->eligiblePaidApprovedQualification();

        $level1 = User::factory()->activated()->create(['applicant_type' => null]);
        $level1->givePermissionTo([
            'verification.pool.view',
            'verification.level1.process',
            'dashboard.view',
        ]);

        $this->actingAs($level1)
            ->post(route('admin.verification.qualifications.issue_certificate', $qualification))
            ->assertForbidden();
    }

    public function test_cannot_issue_for_unverified_qualification(): void
    {
        Storage::fake('local');
        Mail::fake();

        [, $qualification] = $this->eligiblePaidApprovedQualification();
        $qualification->forceFill([
            'verification_state' => VerificationState::UnderLevel2Review,
        ])->save();

        $this->actingAs($this->makeCertificateAdmin())
            ->post(route('admin.verification.qualifications.issue_certificate', $qualification))
            ->assertSessionHasErrors(['qualification']);
    }

    public function test_cannot_issue_when_payment_outstanding(): void
    {
        Storage::fake('local');
        Mail::fake();

        [$application, $qualification] = $this->eligiblePaidApprovedQualification();
        Payment::query()->where('application_id', $application->id)->delete();

        $this->actingAs($this->makeCertificateAdmin())
            ->post(route('admin.verification.qualifications.issue_certificate', $qualification))
            ->assertSessionHasErrors(['payment']);
    }

    public function test_issuing_school_subjects_certificate_without_subjects_is_blocked(): void
    {
        Storage::fake('local');
        Mail::fake();

        [, $qualification] = $this->eligiblePaidApprovedQualification('L1');

        $this->actingAs($this->makeCertificateAdmin())
            ->post(route('admin.verification.qualifications.issue_certificate', $qualification))
            ->assertSessionHasErrors(['qualification']);
    }

    public function test_issuing_school_subjects_certificate_with_incomplete_subjects_is_blocked(): void
    {
        Storage::fake('local');
        Mail::fake();

        [, $qualification] = $this->eligiblePaidApprovedQualification('L1', subjectResults: [
            ['subject_name' => 'Mathematics', 'grade' => ''],
        ]);

        $this->actingAs($this->makeCertificateAdmin())
            ->post(route('admin.verification.qualifications.issue_certificate', $qualification))
            ->assertSessionHasErrors(['qualification']);
    }

    public function test_duplicate_issue_prevented(): void
    {
        Storage::fake('local');
        Mail::fake();

        [, $qualification] = $this->eligiblePaidApprovedQualification();

        $admin = $this->makeCertificateAdmin();

        $this->actingAs($admin)->post(route('admin.verification.qualifications.issue_certificate', $qualification))->assertRedirect();
        Mail::assertQueued(QualificationCertificateIssuedMail::class);

        Mail::fake();
        $this->actingAs($admin)
            ->post(route('admin.verification.qualifications.issue_certificate', $qualification))
            ->assertSessionHasErrors(['qualification']);
    }

    public function test_applicant_can_download_own_certificate(): void
    {
        Storage::fake('local');
        Mail::fake();

        [$application, $qualification, $applicant] = $this->eligiblePaidApprovedQualification();

        $admin = $this->makeCertificateAdmin();

        $this->actingAs($admin)->post(route('admin.verification.qualifications.issue_certificate', $qualification))->assertRedirect();

        $this->actingAs($applicant)
            ->get(route('applicant.applications.qualifications.certificate.download', [$application, $qualification]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_applicant_cannot_download_another_applicants_certificate(): void
    {
        Storage::fake('local');
        Mail::fake();

        [$application, $qualification] = $this->eligiblePaidApprovedQualification();

        $admin = $this->makeCertificateAdmin();
        $this->actingAs($admin)->post(route('admin.verification.qualifications.issue_certificate', $qualification))->assertRedirect();

        $other = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $this->actingAs($other)
            ->get(route('applicant.applications.qualifications.certificate.download', [$application, $qualification]))
            ->assertForbidden();
    }

    public function test_super_admin_can_reissue(): void
    {
        Storage::fake('local');
        Mail::fake();

        [, $qualification] = $this->eligiblePaidApprovedQualification();

        $admin = $this->makeCertificateAdmin(superAdmin: true);

        $this->actingAs($admin)->post(route('admin.verification.qualifications.issue_certificate', $qualification))->assertRedirect();

        $firstId = QualificationCertificate::query()->where('qualification_id', $qualification->id)->value('id');

        Mail::fake();

        $this->actingAs($admin)
            ->post(route('admin.verification.qualifications.issue_certificate', $qualification), ['reissue' => true])
            ->assertRedirect();

        $this->assertDatabaseHas('qualification_certificates', [
            'id' => $firstId,
            'status' => QualificationCertificate::STATUS_REISSUED,
        ]);

        $this->assertSame(1, QualificationCertificate::query()
            ->where('qualification_id', $qualification->id)
            ->where('status', QualificationCertificate::STATUS_ISSUED)
            ->count());
    }

    public function test_missing_watermark_asset_does_not_block_issuance(): void
    {
        Storage::fake('local');
        Mail::fake();
        Log::spy();

        config([
            'certificates.watermark_enabled' => true,
            'certificates.coat_of_arms_path' => 'resources/images/certificates/missing-watermark.png',
        ]);

        [$application, $qualification] = $this->eligiblePaidApprovedQualification();

        $this->mockPdfLoadView(function (string $view, array $data) {
            $this->assertSame('pdf.qualification-certificate', $view);
            $this->assertNull($data['coat_of_arms_watermark_data_uri'] ?? null);
        });

        $this->actingAs($this->makeCertificateAdmin())
            ->post(route('admin.verification.qualifications.issue_certificate', $qualification))
            ->assertRedirect();

        $certificate = QualificationCertificate::query()->where('qualification_id', $qualification->id)->firstOrFail();
        $this->assertFalse((bool) ($certificate->metadata['watermark_asset_present'] ?? true));
        $this->assertSame($application->id, $certificate->application_id);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context) => str_contains($message, 'Certificate watermark asset missing'));
    }

    public function test_watermark_data_uri_is_passed_when_asset_exists(): void
    {
        Storage::fake('local');
        Mail::fake();

        $tempPath = sys_get_temp_dir().'/zaqa-cert-watermark-'.Str::uuid().'.png';
        file_put_contents($tempPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4////fwAJ+wP9xj6xYQAAAABJRU5ErkJggg=='));

        config([
            'certificates.watermark_enabled' => true,
            'certificates.coat_of_arms_path' => $tempPath,
        ]);

        [, $qualification] = $this->eligiblePaidApprovedQualification();

        $this->mockPdfLoadView(function (string $view, array $data) {
            $this->assertSame('pdf.qualification-certificate', $view);
            $this->assertIsString($data['coat_of_arms_watermark_data_uri'] ?? null);
            $this->assertStringStartsWith('data:image/png;base64,', $data['coat_of_arms_watermark_data_uri']);
        });

        $this->actingAs($this->makeCertificateAdmin())
            ->post(route('admin.verification.qualifications.issue_certificate', $qualification))
            ->assertRedirect();

        @unlink($tempPath);
    }

    public function test_certificate_metadata_stores_template_and_watermark_details(): void
    {
        Storage::fake('local');
        Mail::fake();

        config([
            'certificates.verify_url_base' => 'https://verify.example.test/certificates',
            'certificates.watermark_enabled' => false,
        ]);

        [, $qualification] = $this->eligiblePaidApprovedQualification('L1', subjectResults: [
            ['subject_name' => 'Mathematics', 'grade' => '1'],
        ]);

        $this->mockPdfLoadView(fn (string $view, array $data) => null);

        $this->actingAs($this->makeCertificateAdmin())
            ->post(route('admin.verification.qualifications.issue_certificate', $qualification))
            ->assertRedirect();

        $certificate = QualificationCertificate::query()->where('qualification_id', $qualification->id)->firstOrFail();
        $this->assertSame('school_subjects', $certificate->metadata['template_key'] ?? null);
        $this->assertSame(1, $certificate->metadata['template_version'] ?? null);
        $this->assertFalse((bool) ($certificate->metadata['watermark_enabled'] ?? true));
        $this->assertFalse((bool) ($certificate->metadata['watermark_asset_present'] ?? true));
        $this->assertSame('https://verify.example.test/certificates', $certificate->metadata['verification_base_url'] ?? null);
    }

    public function test_qr_verification_base_url_uses_configured_value(): void
    {
        Storage::fake('local');
        Mail::fake();

        config(['certificates.verify_url_base' => 'https://verify.custom.test/lookup']);

        [, $qualification] = $this->eligiblePaidApprovedQualification();

        $this->mockPdfLoadView(function (string $view, array $data) {
            $this->assertSame('pdf.qualification-certificate', $view);
            $this->assertIsString($data['verification_url'] ?? null);
            $this->assertStringStartsWith('https://verify.custom.test/lookup/', $data['verification_url']);
        });

        $this->actingAs($this->makeCertificateAdmin())
            ->post(route('admin.verification.qualifications.issue_certificate', $qualification))
            ->assertRedirect();
    }

    public function test_reissue_uses_current_template_and_preserves_old_certificate_record_and_file(): void
    {
        Storage::fake('local');
        Mail::fake();

        [, $qualification] = $this->eligiblePaidApprovedQualification('L1', subjectResults: [
            ['subject_name' => 'Mathematics', 'grade' => '1'],
        ]);

        $admin = $this->makeCertificateAdmin(superAdmin: true);

        $firstPdfMock = Mockery::mock(DomPdfWrapper::class);
        $firstPdfMock->shouldReceive('setPaper')->once()->with('A4', 'portrait')->andReturnSelf();
        $firstPdfMock->shouldReceive('output')->once()->andReturn('%PDF-first');

        $secondPdfMock = Mockery::mock(DomPdfWrapper::class);
        $secondPdfMock->shouldReceive('setPaper')->once()->with('A4', 'portrait')->andReturnSelf();
        $secondPdfMock->shouldReceive('output')->once()->andReturn('%PDF-second');

        Pdf::shouldReceive('loadView')
            ->twice()
            ->withArgs(fn (string $view, array $data) => $view === 'pdf.qualification-certificate-subjects')
            ->andReturn($firstPdfMock, $secondPdfMock);

        $this->actingAs($admin)->post(route('admin.verification.qualifications.issue_certificate', $qualification))->assertRedirect();

        $firstCertificate = QualificationCertificate::query()
            ->where('qualification_id', $qualification->id)
            ->where('status', QualificationCertificate::STATUS_ISSUED)
            ->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.verification.qualifications.issue_certificate', $qualification), ['reissue' => true])
            ->assertRedirect();

        $firstCertificate->refresh();
        $currentCertificate = QualificationCertificate::query()
            ->where('qualification_id', $qualification->id)
            ->where('status', QualificationCertificate::STATUS_ISSUED)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(QualificationCertificate::STATUS_REISSUED, $firstCertificate->status);
        $this->assertNotSame($firstCertificate->id, $currentCertificate->id);
        $this->assertNotSame($firstCertificate->file_path, $currentCertificate->file_path);
        Storage::disk('local')->assertExists($firstCertificate->file_path);
        Storage::disk('local')->assertExists($currentCertificate->file_path);
        $this->assertSame('school_subjects', $currentCertificate->metadata['template_key'] ?? null);
    }

    public function test_bulk_issue_works_for_default_and_school_subject_templates(): void
    {
        Storage::fake('local');
        Mail::fake();

        [, $defaultQualification] = $this->eligiblePaidApprovedQualification('L6');
        [, $schoolQualification] = $this->eligiblePaidApprovedQualification('L1', subjectResults: [
            ['subject_name' => 'Mathematics', 'grade' => '1'],
        ]);

        $firstPdfMock = Mockery::mock(DomPdfWrapper::class);
        $firstPdfMock->shouldReceive('setPaper')->once()->with('A4', 'portrait')->andReturnSelf();
        $firstPdfMock->shouldReceive('output')->once()->andReturn('%PDF-default');

        $secondPdfMock = Mockery::mock(DomPdfWrapper::class);
        $secondPdfMock->shouldReceive('setPaper')->once()->with('A4', 'portrait')->andReturnSelf();
        $secondPdfMock->shouldReceive('output')->once()->andReturn('%PDF-school');

        $capturedViews = [];

        Pdf::shouldReceive('loadView')
            ->twice()
            ->withArgs(function (string $view, array $data) use (&$capturedViews) {
                $capturedViews[] = $view;

                return true;
            })
            ->andReturn($firstPdfMock, $secondPdfMock);

        $csv = "qualification_id\n{$defaultQualification->id}\n{$schoolQualification->id}\n";
        $file = UploadedFile::fake()->createWithContent('bulk-certificates.csv', $csv);

        $report = app(QualificationCertificateBulkIssueExcelService::class)->import($file, $this->makeCertificateAdmin());

        $this->assertSame(2, $report->created);
        $this->assertSame([], $report->errors);
        $this->assertSame(['pdf.qualification-certificate', 'pdf.qualification-certificate-subjects'], $capturedViews);
    }
}
