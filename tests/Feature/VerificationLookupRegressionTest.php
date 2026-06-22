<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\Qualification;
use App\Models\QualificationCertificate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class VerificationLookupRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_public_qr_certificate_verification_still_works(): void
    {
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'REG-QR-1',
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Approved,
            'verification_state' => VerificationState::CertificateIssued,
            'is_foreign' => false,
            'metadata' => [],
        ]);

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'verification_reference_number' => 'REG-QR-1-01',
            'awarding_institution_name' => 'Test Institution',
            'qualification_holder_name' => 'Jane Holder',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'verification_state' => VerificationState::CertificateIssued,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ]);

        $cert = QualificationCertificate::query()->create([
            'qualification_id' => $qualification->id,
            'application_id' => $application->id,
            'certificate_number' => 'ZAQA-REG-1',
            'verification_token' => 'regression-public-token',
            'file_path' => 'certificates/test.pdf',
            'issued_by_user_id' => User::factory()->activated()->create()->id,
            'issued_at' => now(),
            'status' => QualificationCertificate::STATUS_ISSUED,
            'certificate_type' => QualificationCertificate::TYPE_VERIFICATION,
        ]);

        $this->get(route('certificates.verify', ['token' => $cert->verification_token]))
            ->assertOk();
    }

    public function test_existing_learner_record_submission_api_still_works(): void
    {
        $this->postJson('/api/institution/v1/learner-records', [])->assertStatus(401);
    }

    public function test_existing_applicant_application_tracking_still_works(): void
    {
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'REG-TRACK-1',
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Submitted,
            'verification_state' => VerificationState::AwaitingAssignment,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
        ]);

        $this->actingAs($applicant)
            ->get(route('applicant.applications.track', $application))
            ->assertOk();
    }

    public function test_existing_admin_reference_only_search_still_works(): void
    {
        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->assignRole('Verification Officer Level 2');

        $this->actingAs($admin)
            ->get(route('admin.verification.pool.index', ['application_reference' => '2026-000']))
            ->assertOk();
    }
}
