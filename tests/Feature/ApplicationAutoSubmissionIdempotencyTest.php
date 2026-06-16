<?php

namespace Tests\Feature;

use App\Domain\Applications\ApplicationAutoSubmissionService;
use App\Domain\Fees\QualificationFeeResolver;
use App\Enums\ApplicantType;
use App\Enums\ApplicationStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\VerificationState;
use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\Payment;
use App\Models\Qualification;
use App\Models\QualificationType;
use App\Models\User;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\FeeStructuresSeeder;
use Database\Seeders\QualificationTypesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
            'gender' => 'male',
            'nrc_number' => '111111/11/1',
            'identity_type' => 'nrc',
            'email' => $user->email,
            'phone_primary' => $user->phone_primary,
            'identity_document_uploaded_at' => now(),
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
            'names_as_on_qualification_document' => 'Mary C. Mwansa',
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
        $testRedirect = $this->get($initiate->headers->get('Location'));
        $testRedirect->assertRedirect(route('applicant.applications.feedback.show', $application));
        $this->get($testRedirect->headers->get('Location'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Applicant/Applications/Feedback'));

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

    public function test_auto_submission_sets_per_qualification_sla_and_application_aggregate_deadline_for_mixed_localities(): void
    {
        $submittedAt = Carbon::parse('2026-06-14 09:00:00');
        Carbon::setTestNow($submittedAt);

        try {
            $this->seed(FeeStructuresSeeder::class);

            $applicant = User::factory()->activated()->create([
                'applicant_type' => ApplicantType::Individual,
            ]);

            $application = Application::query()->create([
                'uuid' => (string) Str::uuid(),
                'application_number' => 'ZAQA-MIXED-SLA-001',
                'applicant_user_id' => $applicant->id,
                'applicant_type' => ApplicantType::Individual,
                'service_type' => 'verification',
                'qualification_category' => 'diploma',
                'current_status' => ApplicationStatus::Draft,
                'verification_state' => null,
                'is_foreign' => true,
                'metadata' => [],
            ]);

            $type = QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();

            $localQualification = Qualification::query()->create([
                'application_id' => $application->id,
                'awarding_institution_name' => 'Local Institute',
                'qualification_holder_name' => 'John Doe',
                'country_name_other' => 'Zambia',
                'nrc_passport_number' => '111111/11/1',
                'title_of_qualification' => 'Local Diploma',
            'names_as_on_qualification_document' => 'Mary C. Mwansa',
                'award_date' => now()->subYear()->toDateString(),
                'qualification_type' => $type->zqf_level_code,
                'qualification_type_id' => $type->id,
                'is_foreign_qualification' => false,
                'transcript_required' => false,
            ]);

            $foreignQualification = Qualification::query()->create([
                'application_id' => $application->id,
                'awarding_institution_name' => 'Foreign Institute',
                'qualification_holder_name' => 'John Doe',
                'country_name_other' => 'Kenya',
                'nrc_passport_number' => '111111/11/1',
                'title_of_qualification' => 'Foreign Diploma',
            'names_as_on_qualification_document' => 'Mary C. Mwansa',
                'award_date' => now()->subYear()->toDateString(),
                'qualification_type' => $type->zqf_level_code,
                'qualification_type_id' => $type->id,
                'is_foreign_qualification' => true,
                'transcript_required' => true,
            ]);

            $required = app(QualificationFeeResolver::class)->totalVerificationFeesCents($application->fresh('qualifications'));
            $payment = Payment::query()->create([
                'application_id' => $application->id,
                'method' => PaymentMethod::Card,
                'status' => PaymentStatus::Confirmed,
                'currency' => 'ZMW',
                'amount_cents' => $required,
                'provider' => 'test',
                'confirmed_at' => $submittedAt,
            ]);

            app(ApplicationAutoSubmissionService::class)->submitAfterPaymentSatisfied($application, $payment, null);

            $application->refresh();
            $localQualification->refresh();
            $foreignQualification->refresh();

            $this->assertSame(ApplicationStatus::Submitted, $application->current_status);
            $this->assertSame($submittedAt->toIso8601String(), $localQualification->service_started_at?->toIso8601String());
            $this->assertSame($submittedAt->toIso8601String(), $foreignQualification->service_started_at?->toIso8601String());
            $this->assertSame($submittedAt->copy()->addDays(14)->toIso8601String(), $localQualification->service_deadline_at?->toIso8601String());
            $this->assertSame($submittedAt->copy()->addDays(60)->toIso8601String(), $foreignQualification->service_deadline_at?->toIso8601String());
            $this->assertSame(
                $foreignQualification->service_deadline_at?->toIso8601String(),
                $application->service_deadline_at?->toIso8601String(),
            );
            $this->assertSame(VerificationState::AwaitingAssignment, $localQualification->verification_state);
            $this->assertSame(VerificationState::AwaitingAssignment, $foreignQualification->verification_state);
        } finally {
            Carbon::setTestNow();
        }
    }
}
