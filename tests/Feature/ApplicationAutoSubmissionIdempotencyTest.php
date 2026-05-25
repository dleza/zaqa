<?php

namespace Tests\Feature;

use App\Domain\Applications\ApplicationAutoSubmissionService;
use App\Enums\ApplicantType;
use App\Enums\ApplicationStatus;
use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\Payment;
use App\Models\QualificationType;
use App\Models\User;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\FeeStructuresSeeder;
use Database\Seeders\QualificationTypesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApplicationAutoSubmissionIdempotencyTest extends TestCase
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

    public function test_auto_submission_is_idempotent_on_repeated_calls(): void
    {
        Storage::fake('local');

        $user = User::factory()->activated()->create([
            'applicant_type' => ApplicantType::Individual,
        ]);

        ApplicantProfile::create([
            'user_id' => $user->id,
            'first_name' => 'John',
            'surname' => 'Doe',
            'nrc_number' => '111111/11/1',
            'email' => $user->email,
            'phone_primary' => $user->phone_primary,
        ]);

        $this->actingAs($user);

        $this->post('/applicant/applications', [
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'is_foreign' => false,
            'submitting_for' => 'self',
        ])->assertRedirect();

        $application = Application::query()->firstOrFail();

        $type = QualificationType::query()
            ->where('zqf_level_code', 'L6')
            ->firstOrFail();

        $this->put("/applicant/applications/{$application->id}/qualification", [
            'awarding_institution_name' => 'Test Institute',
            'qualification_holder_name' => 'John Doe',
            'country_name_other' => 'Zambia',
            'awarding_institution_name_other' => 'ZAQA',
            'nrc_passport_number' => '111111/11/1',
            'certificate_number' => 'CERT-001',
            'title_of_qualification' => 'Diploma in Testing',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type_id' => $type->id,
            'subject_results' => [],
        ])->assertRedirect();

        $qualificationId = (int) $application->refresh()->qualifications()->firstOrFail()->id;

        $this->post("/applicant/applications/{$application->id}/documents", [
            'document_type' => 'certificate_copy',
            'qualification_id' => $qualificationId,
            'file' => UploadedFile::fake()->create('certificate.pdf', 100, 'application/pdf'),
        ])->assertRedirect();

        $this->post("/applicant/applications/{$application->id}/documents", [
            'document_type' => 'nrc_copy',
            'file' => UploadedFile::fake()->image('nrc.png')->size(200),
        ])->assertRedirect();

        $this->post("/applicant/applications/{$application->id}/consent/accept", [
            'agreed_by_name' => $user->name,
        ])->assertRedirect();

        $this->patch("/applicant/applications/{$application->id}/wizard-declarations", [
            'accept_terms' => true,
            'confirm_information_correct' => true,
        ])->assertRedirect();

        $initiate = $this->post("/applicant/applications/{$application->id}/payment/initiate-card");
        $initiate->assertRedirect();
        $this->get($initiate->headers->get('Location'))->assertRedirect();

        $application->refresh();
        $this->assertSame(ApplicationStatus::Submitted, $application->current_status);
        $this->assertNotNull($application->submitted_at);

        $payment = Payment::query()->where('application_id', $application->id)->latest('id')->firstOrFail();
        $service = app(ApplicationAutoSubmissionService::class);

        $submittedAt = $application->submitted_at;

        $service->submitAfterPaymentSatisfied($application, $payment, null);
        $service->submitAfterPaymentSatisfied($application, $payment, null);

        $application->refresh();
        $this->assertSame($submittedAt?->toIso8601String(), $application->submitted_at?->toIso8601String());

        $submittedHistoryCount = (int) DB::table('application_status_histories')
            ->where('application_id', $application->id)
            ->where('to_status', ApplicationStatus::Submitted->value)
            ->count();
        $this->assertSame(1, $submittedHistoryCount);
    }
}

