<?php

namespace Tests\Feature;

use App\Enums\ApplicantType;
use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\CertificateSubject;
use App\Models\QualificationType;
use App\Models\User;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\FeeStructuresSeeder;
use Database\Seeders\QualificationTypesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApplicantQualificationSaveValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $applicant;

    private Application $application;

    private QualificationType $diplomaType;

    private QualificationType $schoolType;

    private CertificateSubject $math;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(BillingCategoriesSeeder::class);
        $this->seed(QualificationTypesSeeder::class);
        $this->seed(FeeStructuresSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->applicant = User::factory()->activated()->create([
            'applicant_type' => ApplicantType::Individual,
        ]);

        ApplicantProfile::create([
            'user_id' => $this->applicant->id,
            'first_name' => 'John',
            'surname' => 'Doe',
            'gender' => 'male',
            'nrc_number' => '111111/11/1',
            'identity_type' => 'nrc',
            'email' => $this->applicant->email,
            'phone_primary' => $this->applicant->phone_primary,
            'identity_document_uploaded_at' => now(),
        ]);

        $this->actingAs($this->applicant);

        $this->post('/applicant/applications', [
            'service_type' => 'verification',
            'qualification_category' => 'certificate',
            'is_foreign' => false,
        ])->assertRedirect();

        $this->application = Application::query()->firstOrFail();
        $this->diplomaType = QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();
        $this->schoolType = QualificationType::query()->where('zqf_level_code', 'L2B')->firstOrFail();

        $this->math = CertificateSubject::query()->create([
            'name' => 'Mathematics '.uniqid(),
            'sort_order' => 10,
            'is_active' => true,
        ]);
    }

    public function test_placeholder_institution_name_is_rejected(): void
    {
        $payload = $this->validDiplomaPayload();
        $payload['awarding_institution_id'] = '';
        $payload['awarding_institution_name_other'] = '';
        $payload['awarding_institution_name'] = '—';

        $this->put("/applicant/applications/{$this->application->id}/qualification", $payload)
            ->assertSessionHasErrors(['awarding_institution_id']);
    }

    public function test_missing_names_on_document_is_rejected(): void
    {
        $payload = $this->validDiplomaPayload();
        $payload['names_as_on_qualification_document'] = '';

        $this->put("/applicant/applications/{$this->application->id}/qualification", $payload)
            ->assertSessionHasErrors(['names_as_on_qualification_document']);
    }

    public function test_missing_identifier_is_rejected(): void
    {
        $payload = $this->validDiplomaPayload();
        $payload['certificate_number'] = '';
        $payload['student_number'] = '';
        $payload['examination_number'] = '';

        $this->put("/applicant/applications/{$this->application->id}/qualification", $payload)
            ->assertSessionHasErrors(['certificate_number']);
    }

    public function test_missing_country_is_rejected(): void
    {
        $payload = $this->validDiplomaPayload();
        $payload['country_id'] = null;
        $payload['country_name_other'] = '';

        $this->put("/applicant/applications/{$this->application->id}/qualification", $payload)
            ->assertSessionHasErrors(['country_id']);
    }

    public function test_incomplete_subject_row_is_rejected_for_school_certificates(): void
    {
        $payload = $this->validSchoolPayload();
        $payload['subject_results'] = [
            ['certificate_subject_id' => $this->math->id, 'grade' => ''],
            ['certificate_subject_id' => '', 'grade' => 'A'],
        ];

        $this->put("/applicant/applications/{$this->application->id}/qualification", $payload)
            ->assertSessionHasErrors([
                'subject_results.0.grade',
                'subject_results.1.certificate_subject_id',
            ]);
    }

    public function test_empty_subject_rows_are_ignored_and_save_is_rejected_when_none_complete(): void
    {
        $payload = $this->validSchoolPayload();
        $payload['subject_results'] = [
            ['certificate_subject_id' => '', 'grade' => ''],
            ['certificate_subject_id' => '', 'grade' => ''],
        ];

        $this->put("/applicant/applications/{$this->application->id}/qualification", $payload)
            ->assertSessionHasErrors(['subject_results']);
    }

    public function test_complete_qualification_payload_is_accepted(): void
    {
        $this->put("/applicant/applications/{$this->application->id}/qualification", $this->validDiplomaPayload())
            ->assertRedirect();

        $this->assertDatabaseCount('qualifications', 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function validDiplomaPayload(): array
    {
        return [
            'awarding_institution_name' => 'ZAQA',
            'country_name_other' => 'Zambia',
            'awarding_institution_name_other' => 'ZAQA',
            'certificate_number' => 'CERT-'.uniqid(),
            'title_of_qualification' => 'Diploma in Testing',
            'names_as_on_qualification_document' => 'JOHN DOE',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type_id' => $this->diplomaType->id,
            'subject_results' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validSchoolPayload(): array
    {
        return [
            'awarding_institution_name' => 'Test School',
            'country_name_other' => 'Zambia',
            'awarding_institution_name_other' => 'Test School',
            'certificate_number' => 'CERT-'.uniqid(),
            'title_of_qualification' => $this->schoolType->name,
            'names_as_on_qualification_document' => 'JOHN DOE',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type_id' => $this->schoolType->id,
            'subject_results' => [
                ['certificate_subject_id' => $this->math->id, 'grade' => 'A'],
            ],
        ];
    }
}
