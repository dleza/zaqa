<?php

namespace Tests\Feature;

use App\Enums\ApplicantType;
use App\Enums\ApplicationStatus;
use App\Enums\PaymentStatus;
use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\ConsentForm;
use App\Models\Payment;
use App\Models\Qualification;
use App\Models\QualificationDocument;
use App\Models\QualificationType;
use App\Models\Country;
use App\Models\User;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\FeeStructuresSeeder;
use Database\Seeders\QualificationTypesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ApplicantApplicationFlowTest extends TestCase
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

    public function test_local_application_draft_qualification_documents_consent_and_submission(): void
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

        $draftResponse = $this->post('/applicant/applications', [
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'is_foreign' => false,
        ]);

        $application = Application::query()->firstOrFail();
        $draftResponse->assertRedirect(route('applicant.applications.edit', ['application' => $application, 'step' => 'qualification']));

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'applications.draft_created',
            'module' => 'Applications',
            'entity_type' => Application::class,
            'entity_id' => $application->id,
        ]);

        $type = QualificationType::query()
            ->where('zqf_level_code', 'L6')
            ->firstOrFail();

        $qualificationResponse = $this->put("/applicant/applications/{$application->id}/qualification", [
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
        ]);
        $qualificationResponse->assertRedirect();

        $application->refresh()->load('qualifications');
        $this->assertCount(1, $application->qualifications);
        $qualification = $application->qualifications->first();
        $this->assertNotNull($qualification);

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'qualifications.saved',
            'module' => 'Qualifications',
            'entity_type' => Qualification::class,
            'entity_id' => $qualification->id,
        ]);

        $this->post("/applicant/applications/{$application->id}/documents", [
            'document_type' => 'certificate_copy',
            'qualification_id' => $qualification->id,
            'file' => UploadedFile::fake()->create('certificate.pdf', 100, 'application/pdf'),
        ])->assertRedirect();

        $this->post("/applicant/applications/{$application->id}/documents", [
            'document_type' => 'nrc_copy',
            'file' => UploadedFile::fake()->image('nrc.png')->size(200),
        ])->assertRedirect();

        $this->post("/applicant/applications/{$application->id}/documents", [
            'document_type' => 'transcript',
            'qualification_id' => $qualification->id,
            'file' => UploadedFile::fake()->create('transcript.pdf', 120, 'application/pdf'),
        ])->assertRedirect();

        $this->assertDatabaseHas('qualification_documents', [
            'application_id' => $application->id,
            'qualification_id' => $qualification->id,
            'document_type' => 'certificate_copy',
            'is_current_version' => 1,
        ]);

        $this->post("/applicant/applications/{$application->id}/consent/accept", [
            'agreed_by_name' => $user->name,
        ])->assertRedirect();

        $this->assertDatabaseHas('consent_forms', [
            'qualification_id' => $qualification->id,
            'consent_type' => 'local_embedded',
        ]);

        // Selecting a method should not create a payment attempt/record.
        $this->post("/applicant/applications/{$application->id}/payment/select", [
            'method' => 'card',
        ])->assertRedirect();
        $this->assertDatabaseMissing('payments', [
            'application_id' => $application->id,
        ]);

        // A payment record should be created only when initiation happens.
        $initiate = $this->post("/applicant/applications/{$application->id}/payment/initiate-card");
        $initiate->assertRedirect();

        $this->get($initiate->headers->get('Location'))->assertRedirect();

        $payment = Payment::query()->where('application_id', $application->id)->latest('id')->firstOrFail();
        $payment->refresh();
        $this->assertSame(PaymentStatus::Confirmed, $payment->status);

        // Once confirmed, further payment initiations should be blocked.
        $blocked = $this->post("/applicant/applications/{$application->id}/payment/initiate-mobile-money", [
            'mobile_number' => '0970000000',
        ]);
        $blocked->assertSessionHasErrors(['payment']);

        $submitResponse = $this->post("/applicant/applications/{$application->id}/submit");
        $submitResponse->assertRedirect(route('applicant.applications.feedback.show', $application));

        $application->refresh();
        $this->assertSame(ApplicationStatus::Submitted, $application->current_status);
        $this->assertSame('awaiting_assignment', $application->verification_state?->value);
        $this->assertNotNull($application->submitted_at);
        $this->assertNotNull($application->service_deadline_at);

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'applications.submitted',
            'module' => 'Applications',
            'entity_type' => Application::class,
            'entity_id' => $application->id,
        ]);

        $this->assertDatabaseHas('application_lifecycle_events', [
            'application_id' => $application->id,
            'event_code' => 'draft.created',
        ]);

        $this->assertDatabaseHas('application_lifecycle_events', [
            'application_id' => $application->id,
            'event_code' => 'wizard.step2.qualification_saved',
        ]);

        $this->assertDatabaseHas('application_lifecycle_events', [
            'application_id' => $application->id,
            'event_code' => 'wizard.step3.documents_updated',
        ]);

        $this->assertDatabaseHas('application_lifecycle_events', [
            'application_id' => $application->id,
            'event_code' => 'wizard.step4.consent_accepted',
        ]);

        $this->assertDatabaseHas('application_lifecycle_events', [
            'application_id' => $application->id,
            'event_code' => 'payment.invoice_issued',
        ]);

        $this->assertDatabaseHas('application_lifecycle_events', [
            'application_id' => $application->id,
            'event_code' => 'payment.method_selected',
        ]);

        $this->assertDatabaseHas('application_lifecycle_events', [
            'application_id' => $application->id,
            'event_code' => 'submission.submitted',
        ]);
    }

    public function test_foreign_application_requires_signed_consent_upload_before_submission(): void
    {
        Storage::fake('local');

        $user = User::factory()->activated()->create([
            'applicant_type' => ApplicantType::Individual,
        ]);

        ApplicantProfile::create([
            'user_id' => $user->id,
            'first_name' => 'John',
            'surname' => 'Doe',
            'passport_number' => 'P1234567',
            'email' => $user->email,
            'phone_primary' => $user->phone_primary,
        ]);

        $this->actingAs($user);

        $this->post('/applicant/applications', [
            'service_type' => 'verification',
            'qualification_category' => 'degree',
            'is_foreign' => true,
        ])->assertRedirect();

        $application = Application::query()->firstOrFail();

        $type = QualificationType::query()
            ->where('zqf_level_code', 'L7')
            ->firstOrFail();

        $this->put("/applicant/applications/{$application->id}/qualification", [
            'awarding_institution_name' => 'Foreign University',
            'qualification_holder_name' => 'John Doe',
            'country_id' => Country::query()->where('iso_code', 'ZAF')->value('id'),
            'country_name_other' => 'South Africa',
            'nrc_passport_number' => 'P1234567',
            'certificate_number' => 'CERT-FOREIGN',
            'title_of_qualification' => 'Bachelor of Testing',
            'award_date' => now()->subYears(2)->toDateString(),
            'qualification_type_id' => $type->id,
            'subject_results' => [],
        ])->assertRedirect();

        $application->refresh()->load('qualifications');
        $qualification = $application->qualifications->firstOrFail();
        $qualification->forceFill(['is_foreign_qualification' => true, 'transcript_required' => true])->save();

        $this->post("/applicant/applications/{$application->id}/documents", [
            'document_type' => 'certificate_copy',
            'qualification_id' => $qualification->id,
            'file' => UploadedFile::fake()->create('certificate.pdf', 100, 'application/pdf'),
        ])->assertRedirect();

        $this->post("/applicant/applications/{$application->id}/documents", [
            'document_type' => 'passport_copy',
            'file' => UploadedFile::fake()->image('passport.jpg')->size(200),
        ])->assertRedirect();

        $this->post("/applicant/applications/{$application->id}/documents", [
            'document_type' => 'transcript',
            'qualification_id' => $qualification->id,
            'file' => UploadedFile::fake()->create('transcript.pdf', 120, 'application/pdf'),
        ])->assertRedirect();

        $failedSubmit = $this->post("/applicant/applications/{$application->id}/submit");
        $failedSubmit->assertSessionHasErrors(['consent']);

        $this->post("/applicant/applications/{$application->id}/consent/foreign-upload", [
            'qualification_id' => $qualification->id,
            'file' => UploadedFile::fake()->create('consent.pdf', 80, 'application/pdf'),
            'zaqa_file' => UploadedFile::fake()->create('zaqa-consent.pdf', 80, 'application/pdf'),
            'source_awarding_institution_name' => 'Foreign University',
        ])->assertRedirect();

        $this->assertDatabaseHas('consent_forms', [
            'qualification_id' => $qualification->id,
            'consent_type' => 'foreign_uploaded',
        ]);

        $consent = ConsentForm::query()->where('qualification_id', $qualification->id)->firstOrFail();
        $this->assertNotNull($consent->uploaded_document_id);
        $this->assertNotNull($consent->zaqa_uploaded_document_id);

        $failedPaymentSubmit = $this->post("/applicant/applications/{$application->id}/submit");
        $failedPaymentSubmit->assertSessionHasErrors(['payment']);

        $this->post("/applicant/applications/{$application->id}/payment/select", [
            'method' => 'card',
        ])->assertRedirect();
        $this->assertDatabaseMissing('payments', [
            'application_id' => $application->id,
        ]);

        $initiate = $this->post("/applicant/applications/{$application->id}/payment/initiate-card");
        $initiate->assertRedirect();
        $this->get($initiate->headers->get('Location'))->assertRedirect();

        $submit = $this->post("/applicant/applications/{$application->id}/submit");
        $submit->assertRedirect(route('applicant.applications.feedback.show', $application));
    }

    public function test_manual_bank_payment_proof_requires_finance_approval_before_submission(): void
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

        $finance = User::factory()->activated()->create([
            'applicant_type' => null,
        ]);
        $finance->assignRole('Finance Officer');

        $this->actingAs($user);

        $this->post('/applicant/applications', [
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'is_foreign' => false,
        ])->assertRedirect();

        $application = Application::query()->firstOrFail();

        $type = QualificationType::query()
            ->where('zqf_level_code', 'L6')
            ->firstOrFail();

        $this->put("/applicant/applications/{$application->id}/qualification", [
            'awarding_institution_name' => 'Test Institute',
            'qualification_holder_name' => 'John Doe',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'certificate_number' => 'CERT-001',
            'title_of_qualification' => 'Diploma in Testing',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type_id' => $type->id,
            'subject_results' => [],
        ])->assertRedirect();

        $this->post("/applicant/applications/{$application->id}/documents", [
            'document_type' => 'certificate_copy',
            'qualification_id' => $application->refresh()->qualifications()->firstOrFail()->id,
            'file' => UploadedFile::fake()->create('certificate.pdf', 100, 'application/pdf'),
        ])->assertRedirect();

        $this->post("/applicant/applications/{$application->id}/documents", [
            'document_type' => 'nrc_copy',
            'file' => UploadedFile::fake()->image('nrc.png')->size(200),
        ])->assertRedirect();

        $this->post("/applicant/applications/{$application->id}/documents", [
            'document_type' => 'transcript',
            'qualification_id' => $application->refresh()->qualifications()->firstOrFail()->id,
            'file' => UploadedFile::fake()->create('transcript.pdf', 120, 'application/pdf'),
        ])->assertRedirect();

        $this->post("/applicant/applications/{$application->id}/consent/accept", [
            'agreed_by_name' => $user->name,
        ])->assertRedirect();

        $this->post("/applicant/applications/{$application->id}/payment/select", [
            'method' => 'bank_transfer',
        ])->assertRedirect();
        $this->assertDatabaseMissing('payments', [
            'application_id' => $application->id,
        ]);

        $this->post("/applicant/applications/{$application->id}/payment/upload-proof", [
            'file' => UploadedFile::fake()->create('proof.pdf', 50, 'application/pdf'),
        ])->assertRedirect();

        $payment = Payment::query()->where('application_id', $application->id)->latest('id')->firstOrFail();

        $failedSubmit = $this->post("/applicant/applications/{$application->id}/submit");
        $failedSubmit->assertSessionHasErrors(['payment']);

        $this->actingAs($finance);
        $this->post("/finance/payments/{$payment->id}/approve", [
            'comment' => 'Looks valid.',
        ])->assertRedirect();

        $this->actingAs($user);
        $this->post("/applicant/applications/{$application->id}/submit")->assertRedirect();
    }

    public function test_document_download_requires_signed_url_and_is_audited(): void
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
            'qualification_category' => 'certificate',
            'is_foreign' => false,
        ])->assertRedirect();

        $application = Application::query()->firstOrFail();

        $type = QualificationType::query()
            ->where('zqf_level_code', 'L4')
            ->firstOrFail();

        $this->put("/applicant/applications/{$application->id}/qualification", [
            'awarding_institution_name' => 'Test Institute',
            'qualification_holder_name' => 'John Doe',
            'country_name_other' => 'Zambia',
            'awarding_institution_name_other' => 'ZAQA',
            'nrc_passport_number' => '111111/11/1',
            'certificate_number' => 'CERT-001',
            'title_of_qualification' => 'Certificate in Testing',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type_id' => $type->id,
            'subject_results' => [],
        ])->assertRedirect();

        $this->post("/applicant/applications/{$application->id}/documents", [
            'document_type' => 'certificate_copy',
            'qualification_id' => $application->refresh()->qualifications()->firstOrFail()->id,
            'file' => UploadedFile::fake()->create('certificate.pdf', 100, 'application/pdf'),
        ])->assertRedirect();

        $document = QualificationDocument::query()
            ->where('application_id', $application->id)
            ->where('document_type', 'certificate_copy')
            ->firstOrFail();

        $this->get("/applicant/documents/{$document->id}/download")->assertForbidden();

        $signedUrl = URL::temporarySignedRoute('applicant.documents.download', now()->addMinute(), ['document' => $document->id]);
        $this->get($signedUrl)->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'documents.downloaded',
            'module' => 'Documents',
            'entity_type' => QualificationDocument::class,
            'entity_id' => $document->id,
            'actor_user_id' => $user->id,
        ]);
    }
}
