<?php

namespace Tests\Feature;

use App\Enums\ApplicantType;
use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\CertificateSubject;
use App\Models\Qualification;
use App\Models\QualificationSubjectResult;
use App\Models\QualificationType;
use App\Models\User;
use App\Support\Qualifications\CertificateSubjectGrade;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\FeeStructuresSeeder;
use Database\Seeders\QualificationTypesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CertificateSubjectGradeValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $applicant;

    private Application $application;

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
        $this->schoolType = QualificationType::query()->where('zqf_level_code', 'L2B')->firstOrFail();

        $this->math = CertificateSubject::query()->create([
            'name' => 'Mathematics '.uniqid(),
            'sort_order' => 10,
            'is_active' => true,
        ]);
    }

    public function test_workspace_includes_subject_grade_options(): void
    {
        $this->get("/applicant/applications/{$this->application->id}/qualifications/create")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Applicant/Applications/Qualifications/Workspace')
                ->has('subjectGradeOptions', count(CertificateSubjectGrade::allowed()))
                ->where('subjectGradeOptions.0', '1')
                ->where('subjectGradeOptions.8', '9')
                ->where('subjectGradeOptions.9', 'A')
                ->where('subjectGradeOptions.34', 'Z')
            );
    }

    public function test_applicant_can_save_allowed_numeric_and_letter_grades(): void
    {
        foreach (['1', '9', 'A', 'Z'] as $grade) {
            $response = $this->put("/applicant/applications/{$this->application->id}/qualification", $this->qualificationPayload($grade));
            $response->assertRedirect();
            $qualification = $this->application->refresh()->qualifications()->firstOrFail();
            $this->assertSame($grade, $qualification->subjectResults()->first()?->grade);
        }
    }

    public function test_lowercase_letter_grade_is_normalized_to_uppercase(): void
    {
        $this->put("/applicant/applications/{$this->application->id}/qualification", $this->qualificationPayload('b'))
            ->assertRedirect();

        $qualification = $this->application->refresh()->qualifications()->firstOrFail();
        $this->assertSame('B', $qualification->subjectResults()->first()?->grade);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidGradeDataProvider(): array
    {
        return [
            'ten' => ['10'],
            'plus' => ['A+'],
            'pass' => ['Pass'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidGradeDataProvider')]
    public function test_invalid_grade_values_are_rejected(string $grade): void
    {
        $this->put("/applicant/applications/{$this->application->id}/qualification", $this->qualificationPayload($grade))
            ->assertSessionHasErrors(['subject_results.0.grade']);
    }

    public function test_missing_grade_is_rejected_for_required_subject_results(): void
    {
        $payload = $this->qualificationPayload('');
        $payload['subject_results'][0]['grade'] = '';

        $this->put("/applicant/applications/{$this->application->id}/qualification", $payload)
            ->assertSessionHasErrors(['subject_results.0.grade']);
    }

    public function test_workspace_edit_with_legacy_invalid_grade_renders_without_error(): void
    {
        $qualification = Qualification::query()->create([
            'application_id' => $this->application->id,
            'awarding_institution_name' => 'Test School',
            'qualification_holder_name' => 'John Doe',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => $this->schoolType->name,
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $this->schoolType->zqf_level_code,
            'qualification_type_id' => $this->schoolType->id,
            'is_foreign_qualification' => false,
        ]);

        QualificationSubjectResult::query()->create([
            'qualification_id' => $qualification->id,
            'certificate_subject_id' => $this->math->id,
            'subject_name' => $this->math->name,
            'grade' => 'B+',
            'display_order' => 0,
        ]);

        $this->get("/applicant/applications/{$this->application->id}/qualifications/{$qualification->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Applicant/Applications/Qualifications/Workspace')
                ->where('application.qualifications.0.subject_results.0.grade', 'B+')
            );
    }

    public function test_certificate_subject_display_still_shows_saved_grade(): void
    {
        $qualification = Qualification::query()->create([
            'application_id' => $this->application->id,
            'awarding_institution_name' => 'Test School',
            'qualification_holder_name' => 'John Doe',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => $this->schoolType->name,
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $this->schoolType->zqf_level_code,
            'qualification_type_id' => $this->schoolType->id,
            'is_foreign_qualification' => false,
        ]);

        QualificationSubjectResult::query()->create([
            'qualification_id' => $qualification->id,
            'certificate_subject_id' => $this->math->id,
            'subject_name' => $this->math->name,
            'grade' => 'A',
            'display_order' => 0,
        ]);

        $this->get("/applicant/applications/{$this->application->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('application.qualifications', 1)
                ->where('application.qualifications.0.subject_results.0.grade', 'A')
            );
    }

    /**
     * @return array<string, mixed>
     */
    private function qualificationPayload(string $grade): array
    {
        return [
            'awarding_institution_name' => 'School',
            'qualification_holder_name' => 'John Doe',
            'country_name_other' => 'Zambia',
            'awarding_institution_name_other' => 'Test School',
            'nrc_passport_number' => '111111/11/1',
            'certificate_number' => 'CERT-'.uniqid(),
            'title_of_qualification' => 'Grade 12 Certificate',
            'names_as_on_qualification_document' => 'JOHN DOE',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type_id' => $this->schoolType->id,
            'subject_results' => [
                ['certificate_subject_id' => $this->math->id, 'grade' => $grade],
            ],
        ];
    }
}
