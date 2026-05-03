<?php

namespace Tests\Feature;

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
use App\Models\QualificationType;
use App\Models\User;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\FeeStructuresSeeder;
use Database\Seeders\QualificationTypesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
    private function eligiblePaidApprovedQualification(): array
    {
        $type = QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();
        $applicant = User::factory()->activated()->create([
            'applicant_type' => 'individual',
            'email' => 'app-cert-test@example.test',
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
            'verification_reference_number' => 'ZAQA-Q-TEST-1',
            'awarding_institution_name' => 'Test Institution',
            'qualification_holder_name' => 'Jane Holder',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '999999/99/9',
            'title_of_qualification' => 'Diploma in Testing',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'verification_state' => VerificationState::ApprovedForCertificate,
        ]);

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

    public function test_admin_can_issue_certificate_for_verified_paid_qualification(): void
    {
        Storage::fake('local');
        Mail::fake();

        [$application, $qualification, $applicant] = $this->eligiblePaidApprovedQualification();

        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->givePermissionTo([
            'verification.pool.view',
            'verification.certificate.issue',
            'dashboard.view',
        ]);

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

        Mail::assertSent(QualificationCertificateIssuedMail::class, function (QualificationCertificateIssuedMail $mail) use ($applicant) {
            return $mail->hasTo($applicant->email);
        });

        $cert = QualificationCertificate::query()->where('qualification_id', $qualification->id)->firstOrFail();
        Storage::disk('local')->assertExists($cert->file_path);
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

        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->givePermissionTo(['verification.pool.view', 'verification.certificate.issue', 'dashboard.view']);

        $this->actingAs($admin)
            ->post(route('admin.verification.qualifications.issue_certificate', $qualification))
            ->assertSessionHasErrors(['qualification']);
    }

    public function test_cannot_issue_when_payment_outstanding(): void
    {
        Storage::fake('local');
        Mail::fake();

        [$application, $qualification] = $this->eligiblePaidApprovedQualification();
        Payment::query()->where('application_id', $application->id)->delete();

        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->givePermissionTo(['verification.pool.view', 'verification.certificate.issue', 'dashboard.view']);

        $this->actingAs($admin)
            ->post(route('admin.verification.qualifications.issue_certificate', $qualification))
            ->assertSessionHasErrors(['payment']);
    }

    public function test_duplicate_issue_prevented(): void
    {
        Storage::fake('local');
        Mail::fake();

        [, $qualification] = $this->eligiblePaidApprovedQualification();

        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->givePermissionTo(['verification.pool.view', 'verification.certificate.issue', 'dashboard.view']);

        $this->actingAs($admin)->post(route('admin.verification.qualifications.issue_certificate', $qualification))->assertRedirect();
        Mail::assertSent(QualificationCertificateIssuedMail::class);

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

        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->givePermissionTo(['verification.pool.view', 'verification.certificate.issue', 'dashboard.view']);

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

        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->givePermissionTo(['verification.pool.view', 'verification.certificate.issue', 'dashboard.view']);
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

        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->assignRole('Super Admin');

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
}
