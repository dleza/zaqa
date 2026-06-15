<?php

namespace Tests\Feature;

use App\Enums\ApplicantType;
use App\Enums\ApplicationStatus;
use App\Enums\PaymentStatus;
use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\AwardingInstitution;
use App\Models\CertificateSubject;
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
            'gender' => 'male',
            'nrc_number' => '111111/11/1',
            'identity_type' => 'nrc',
            'email' => $user->email,
            'phone_primary' => $user->phone_primary,
            'identity_document_uploaded_at' => now(),
        ]);

        $this->actingAs($user);

        $draftResponse = $this->post('/applicant/applications', [
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'is_foreign' => false,
            'submitting_for' => 'self',
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

        $this->patch("/applicant/applications/{$application->id}/wizard-declarations", [
            'accept_terms' => true,
            'confirm_information_correct' => true,
        ])->assertRedirect();

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

        $testRedirect = $this->get($initiate->headers->get('Location'));
        $testRedirect->assertRedirect(route('applicant.applications.feedback.show', $application));
        $this->get($testRedirect->headers->get('Location'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Applicant/Applications/Feedback'));

        $payment = Payment::query()->where('application_id', $application->id)->latest('id')->firstOrFail();
        $payment->refresh();
        $this->assertSame(PaymentStatus::Confirmed, $payment->status);

        // Once confirmed, further payment initiations should be blocked.
        $blocked = $this->post("/applicant/applications/{$application->id}/payment/initiate-mobile-money", [
            'mobile_number' => '0970000000',
        ]);
        $blocked->assertSessionHasErrors(['payment']);

        $application->refresh();
        $this->assertSame(ApplicationStatus::Submitted, $application->current_status);
        $this->assertSame('awaiting_assignment', $application->verification_state?->value);
        $this->assertNotNull($application->submitted_at);
        $this->assertNotNull($application->service_deadline_at);

        $qualification->refresh();
        $this->assertNotNull($qualification->verification_reference_number);
        $this->assertStringStartsWith('ZAQA-Q-', $qualification->verification_reference_number);
        $this->assertNotNull($qualification->auto_verification_attempted_at);
        $this->assertSame('awaiting_assignment', $qualification->verification_state?->value);
        $this->assertNotNull($qualification->service_started_at);
        $this->assertNotNull($qualification->service_deadline_at);
        $this->assertSame(
            $application->service_deadline_at?->toIso8601String(),
            $qualification->service_deadline_at?->toIso8601String(),
        );

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'applications.auto_submitted',
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
            'event_code' => 'submission.auto_submitted',
        ]);

        $this->post("/applicant/applications/{$application->id}/documents", [
            'document_type' => 'certificate_copy',
            'qualification_id' => $qualification->id,
            'file' => UploadedFile::fake()->create('extra.pdf', 1, 'application/pdf'),
        ])->assertForbidden();
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
            'gender' => 'male',
            'passport_number' => 'P1234567',
            'identity_type' => 'passport',
            'email' => $user->email,
            'phone_primary' => $user->phone_primary,
            'identity_document_uploaded_at' => now(),
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

        $southAfricaId = (int) (Country::query()->where('iso_code', 'ZAF')->value('id')
            ?: Country::query()->create(['iso_code' => 'ZAF', 'name' => 'South Africa', 'is_active' => true, 'sort_order' => 0])->id);
        $awardingInstitution = AwardingInstitution::query()->create([
            'country_id' => $southAfricaId,
            'name' => 'Foreign University',
            'is_active' => true,
            'sort_order' => 0,
            'consent_form_path' => 'private/awarding-institutions/seed/consent-form/template.pdf',
        ]);
        Storage::disk('local')->put($awardingInstitution->consent_form_path, 'template');

        $this->put("/applicant/applications/{$application->id}/qualification", [
            'awarding_institution_name' => 'Foreign University',
            'awarding_institution_id' => $awardingInstitution->id,
            'qualification_holder_name' => 'John Doe',
            'country_id' => $southAfricaId,
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
        $qualification->forceFill([
            'awarding_institution_id' => $awardingInstitution->id,
            'is_foreign_qualification' => true,
            'transcript_required' => true,
        ])->save();

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

        $this->patch("/applicant/applications/{$application->id}/wizard-declarations", [
            'accept_terms' => true,
            'confirm_information_correct' => true,
        ])->assertRedirect();

        $this->post("/applicant/applications/{$application->id}/payment/select", [
            'method' => 'card',
        ])->assertRedirect();

        $blockedInitiate = $this->post("/applicant/applications/{$application->id}/payment/initiate-card");
        $blockedInitiate->assertSessionHasErrors(['consent']);

        $this->post("/applicant/applications/{$application->id}/consent/foreign-upload", [
            'qualification_id' => $qualification->id,
            'file' => UploadedFile::fake()->image('consent.jpg')->size(200),
            'source_awarding_institution_name' => 'Foreign University',
        ])->assertRedirect();

        $this->assertDatabaseHas('consent_forms', [
            'qualification_id' => $qualification->id,
            'consent_type' => 'foreign_uploaded',
        ]);

        $consent = ConsentForm::query()->where('qualification_id', $qualification->id)->firstOrFail();
        $this->assertNotNull($consent->uploaded_document_id);
        $this->assertNull($consent->zaqa_uploaded_document_id);

        $initiate = $this->post("/applicant/applications/{$application->id}/payment/initiate-card");
        $initiate->assertRedirect();
        $testRedirect = $this->get($initiate->headers->get('Location'));
        $testRedirect->assertRedirect(route('applicant.applications.feedback.show', $application));
        $this->get($testRedirect->headers->get('Location'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Applicant/Applications/Feedback'));

        $application->refresh();
        $this->assertSame(ApplicationStatus::Submitted, $application->current_status);
    }

    public function test_zambia_country_iso_alpha3_zmb_is_classified_as_local_not_foreign(): void
    {
        $user = User::factory()->activated()->create([
            'applicant_type' => ApplicantType::Individual,
        ]);

        ApplicantProfile::create([
            'user_id' => $user->id,
            'first_name' => 'Jane',
            'surname' => 'Doe',
            'gender' => 'female',
            'nrc_number' => '222222/22/2',
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
        ])->assertRedirect();

        $application = Application::query()->firstOrFail();

        $type = QualificationType::query()
            ->where('zqf_level_code', 'L6')
            ->firstOrFail();

        $zambiaId = Country::query()->create([
            'iso_code' => 'ZMB',
            'name' => 'Zambia',
            'is_active' => true,
            'sort_order' => 0,
        ])->id;

        $institution = AwardingInstitution::query()->create([
            'country_id' => $zambiaId,
            'name' => 'Zambian Institution',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->put("/applicant/applications/{$application->id}/qualification", [
            'awarding_institution_id' => $institution->id,
            'awarding_institution_name' => 'Zambian Institution',
            'qualification_holder_name' => 'Jane Doe',
            'country_id' => $zambiaId,
            'nrc_passport_number' => '222222/22/2',
            'certificate_number' => 'CERT-ZMB',
            'title_of_qualification' => 'Diploma in Zambia',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type_id' => $type->id,
            'subject_results' => [],
        ])->assertRedirect();

        $qualification = $application->refresh()->qualifications->firstOrFail();

        $this->assertFalse(
            (bool) $qualification->is_foreign_qualification,
            'Seeded countries use ZMB; it must be treated as Zambia (local), not foreign.'
        );
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
            'gender' => 'male',
            'nrc_number' => '111111/11/1',
            'identity_type' => 'nrc',
            'email' => $user->email,
            'phone_primary' => $user->phone_primary,
            'identity_document_uploaded_at' => now(),
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
            'awarding_institution_name_other' => 'Test Institute',
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

        $this->patch("/applicant/applications/{$application->id}/wizard-declarations", [
            'accept_terms' => true,
            'confirm_information_correct' => true,
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
        $application->refresh();
        $this->assertNull($application->submitted_at);

        $this->actingAs($finance);
        $this->post("/finance/payments/{$payment->id}/approve", [
            'comment' => 'Looks valid.',
        ])->assertRedirect();

        $application->refresh();
        $this->assertSame(ApplicationStatus::Submitted, $application->current_status);
        $this->assertNotNull($application->submitted_at);
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

    public function test_school_certificate_qualification_stores_catalog_subject_results(): void
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

        $math = CertificateSubject::query()->create([
            'name' => 'Mathematics '.uniqid(),
            'sort_order' => 10,
            'is_active' => true,
        ]);
        $english = CertificateSubject::query()->create([
            'name' => 'English '.uniqid(),
            'sort_order' => 20,
            'is_active' => true,
        ]);

        $this->post('/applicant/applications', [
            'service_type' => 'verification',
            'qualification_category' => 'certificate',
            'is_foreign' => false,
        ])->assertRedirect();

        $application = Application::query()->firstOrFail();

        $type = QualificationType::query()
            ->where('zqf_level_code', 'L1')
            ->firstOrFail();

        $this->assertTrue((bool) $type->requires_subject_results);

        $this->put("/applicant/applications/{$application->id}/qualification", [
            'awarding_institution_name' => 'School',
            'qualification_holder_name' => 'John Doe',
            'country_name_other' => 'Zambia',
            'awarding_institution_name_other' => 'Test School',
            'nrc_passport_number' => '111111/11/1',
            'certificate_number' => 'CERT-G7',
            'title_of_qualification' => 'Grade 7 Certificate',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type_id' => $type->id,
            'subject_results' => [
                ['certificate_subject_id' => $math->id, 'grade' => '1'],
                ['certificate_subject_id' => $english->id, 'grade' => '2'],
            ],
        ])->assertRedirect();

        $qualification = $application->refresh()->qualifications()->firstOrFail();
        $rows = $qualification->subjectResults()->orderBy('display_order')->get();

        $this->assertCount(2, $rows);
        $this->assertSame($math->id, (int) $rows[0]->certificate_subject_id);
        $this->assertSame($math->name, $rows[0]->subject_name);
        $this->assertSame('1', $rows[0]->grade);
        $this->assertSame($english->id, (int) $rows[1]->certificate_subject_id);
    }

    public function test_create_draft_saves_inline_nrc_to_applicant_profile_when_missing(): void
    {
        Storage::fake('local');

        $user = User::factory()->activated()->create([
            'applicant_type' => ApplicantType::Individual,
        ]);

        ApplicantProfile::create([
            'user_id' => $user->id,
            'first_name' => 'Jane',
            'surname' => 'Doe',
            'nrc_number' => null,
            'passport_number' => null,
            'gender' => null,
            'identity_type' => null,
            'email' => $user->email,
            'phone_primary' => $user->phone_primary,
            'identity_document_uploaded_at' => null,
        ]);

        $this->actingAs($user);

        $response = $this->post('/applicant/applications', [
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'is_foreign' => false,
            'submitting_for' => 'self',
            'gender' => 'female',
            'identity_type' => 'nrc',
            'identity_number' => '888888/88/8',
            'identity_file' => UploadedFile::fake()->image('nrc.png')->size(200),
        ]);

        $response->assertRedirect();

        $user->refresh();
        $this->assertSame('888888/88/8', $user->applicantProfile?->nrc_number);
        $this->assertSame('nrc', $user->applicantProfile?->identity_type);
        $this->assertSame('female', $user->applicantProfile?->gender);
        $this->assertNotNull($user->applicantProfile?->identity_document_uploaded_at);
    }

    public function test_on_behalf_application_draft_persists_subject_gender_on_application_metadata(): void
    {
        Storage::fake('local');

        $user = User::factory()->activated()->create([
            'applicant_type' => ApplicantType::Individual,
        ]);

        $this->actingAs($user);

        $this->post('/applicant/applications', [
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'is_foreign' => false,
            'submitting_for' => 'other',
            'subject_first_name' => 'Mary',
            'subject_other_names' => 'Jane',
            'subject_last_name' => 'Mwale',
            'gender' => 'female',
            'identity_type' => 'passport',
            'identity_number' => 'P1234567',
            'identity_file' => UploadedFile::fake()->image('passport.png')->size(200),
        ])->assertRedirect();

        $application = Application::query()->firstOrFail();
        $meta = (array) ($application->metadata ?? []);

        $this->assertSame('other', $meta['submitting_for'] ?? null);

        $subject = $meta['verification_subject'] ?? null;
        $this->assertIsArray($subject);
        $this->assertSame('Mary Jane Mwale', $subject['full_name'] ?? null);
        $this->assertSame('female', $subject['gender'] ?? null);
        $this->assertSame('passport', $subject['identity_type'] ?? null);
        $this->assertSame('P1234567', $subject['passport_number'] ?? null);
        $this->assertSame('applicant_account', $meta['notification_contact_mode'] ?? null);
    }
}
