<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\Qualification;
use App\Models\QualificationCertificate;
use App\Models\QualificationType;
use App\Models\User;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\FeeStructuresSeeder;
use Database\Seeders\QualificationTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CertificateVerificationPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BillingCategoriesSeeder::class);
        $this->seed(QualificationTypesSeeder::class);
        $this->seed(FeeStructuresSeeder::class);
    }

    /**
     * @return array{Application, Qualification, QualificationCertificate}
     */
    private function createCertificateFixture(
        string $status = QualificationCertificate::STATUS_ISSUED,
        ?Qualification $qualification = null,
        string $qualificationTypeCode = 'L6',
        array $subjectRows = [],
    ): array
    {
        $type = QualificationType::query()->where('zqf_level_code', $qualificationTypeCode)->firstOrFail();
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $application = $qualification?->application ?: Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-VER-'.random_int(10000, 99999),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Approved,
            'verification_state' => VerificationState::CertificateIssued,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
            'approved_at' => now(),
        ]);

        $qualification ??= Qualification::query()->create([
            'application_id' => $application->id,
            'verification_reference_number' => 'ZAQA-Q-'.Str::upper((string) Str::ulid()),
            'awarding_institution_name' => 'Test Institution',
            'qualification_holder_name' => 'Jane Holder',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '999999/99/9',
            'title_of_qualification' => 'Diploma in Testing',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'verification_state' => VerificationState::CertificateIssued,
        ]);

        $issuer = User::factory()->activated()->create(['applicant_type' => null]);

        $certificate = QualificationCertificate::query()->create([
            'qualification_id' => $qualification->id,
            'application_id' => $application->id,
            'certificate_number' => 'ZAQA-CVEQ-'.now()->year.'-'.random_int(100000, 999999),
            'zaqa_reference_number' => $qualification->verification_reference_number,
            'verification_token' => Str::random(48),
            'file_path' => 'qualification-certificates/test.pdf',
            'issued_by_user_id' => $issuer->id,
            'issued_at' => now(),
            'recipient_email' => $applicant->email,
            'status' => $status,
            'metadata' => [],
        ]);

        foreach ($subjectRows as $index => $row) {
            $qualification->subjectResults()->create([
                'subject_name' => $row['subject_name'] ?? null,
                'grade' => $row['grade'] ?? null,
                'display_order' => $row['display_order'] ?? ($index + 1),
            ]);
        }

        return [$application, $qualification, $certificate];
    }

    public function test_public_verification_page_shows_valid_certificate_details(): void
    {
        [, $qualification, $certificate] = $this->createCertificateFixture();

        $this->get(route('certificates.verify', ['token' => $certificate->verification_token]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Certificates/Verify')
                ->where('verification.found', true)
                ->where('verification.status', QualificationCertificate::STATUS_ISSUED)
                ->where('verification.certificate.certificate_number', $certificate->certificate_number)
                ->where('verification.certificate.holder_name', $qualification->qualification_holder_name)
                ->where('verification.certificate.qualification_title', $qualification->title_of_qualification)
                ->where('verification.certificate.template_key', QualificationType::CERTIFICATE_TEMPLATE_DEFAULT)
                ->where('verification.certificate.subject_count', 0)
            );
    }

    public function test_public_verification_page_includes_subject_results_for_school_certificates(): void
    {
        [, , $certificate] = $this->createCertificateFixture(
            QualificationCertificate::STATUS_ISSUED,
            null,
            'L1',
            [
                ['subject_name' => 'Mathematics', 'grade' => 'A', 'display_order' => 2],
                ['subject_name' => 'English Language', 'grade' => 'B+', 'display_order' => 1],
            ],
        );

        $this->get(route('certificates.verify', ['token' => $certificate->verification_token]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Certificates/Verify')
                ->where('verification.certificate.template_key', QualificationType::CERTIFICATE_TEMPLATE_SCHOOL_SUBJECTS)
                ->where('verification.certificate.subject_count', 2)
                ->where('verification.certificate.subject_results.0.subject_name', 'English Language')
                ->where('verification.certificate.subject_results.0.grade', 'B+')
                ->where('verification.certificate.subject_results.1.subject_name', 'Mathematics')
                ->where('verification.certificate.subject_results.1.grade', 'A')
            );
    }

    public function test_public_verification_page_shows_superseded_status_for_reissued_certificate(): void
    {
        [, $qualification, $oldCertificate] = $this->createCertificateFixture(QualificationCertificate::STATUS_REISSUED);
        [, , $newCertificate] = $this->createCertificateFixture(QualificationCertificate::STATUS_ISSUED, $qualification);

        $this->get(route('certificates.verify', ['token' => $oldCertificate->verification_token]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Certificates/Verify')
                ->where('verification.status', QualificationCertificate::STATUS_REISSUED)
                ->where('verification.certificate.replacement_certificate_number', $newCertificate->certificate_number)
            );
    }

    public function test_public_verification_page_handles_older_schema_without_template_key_column(): void
    {
        [, , $certificate] = $this->createCertificateFixture(QualificationCertificate::STATUS_ISSUED, null, 'L1');

        Schema::shouldReceive('hasColumn')
            ->once()
            ->with('qualification_types', 'certificate_template_key')
            ->andReturn(false);

        $this->get(route('certificates.verify', ['token' => $certificate->verification_token]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Certificates/Verify')
                ->where('verification.certificate.template_key', QualificationType::CERTIFICATE_TEMPLATE_SCHOOL_SUBJECTS)
            );
    }

    public function test_public_verification_page_shows_revoked_status(): void
    {
        [, , $certificate] = $this->createCertificateFixture(QualificationCertificate::STATUS_REVOKED);

        $this->get(route('certificates.verify', ['token' => $certificate->verification_token]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Certificates/Verify')
                ->where('verification.status', QualificationCertificate::STATUS_REVOKED)
                ->where('verification.status_label', 'Revoked certificate')
            );
    }

    public function test_public_verification_page_returns_not_found_state_for_unknown_token(): void
    {
        $this->get(route('certificates.verify', ['token' => Str::random(48)]))
            ->assertNotFound()
            ->assertInertia(fn ($page) => $page
                ->component('Certificates/Verify')
                ->where('verification.found', false)
                ->where('verification.status', 'not_found')
            );
    }
}
