<?php

namespace Tests\Feature;

use App\Domain\Applications\ApplicationNotificationContact;
use App\Domain\Certificates\QualificationCertificateService;
use App\Domain\Fees\QualificationFeeResolver;
use App\Domain\Verification\DecisionService;
use App\Enums\ApplicationStatus;
use App\Enums\ApplicantType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\VerificationState;
use App\Mail\ApplicationOutcomeNotificationMail;
use App\Mail\QualificationCertificateIssuedMail;
use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\Payment;
use App\Models\Qualification;
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
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ApplicationNotificationContactTest extends TestCase
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

    public function test_on_behalf_flow_defaults_to_applicant_account_contact(): void
    {
        Storage::fake('local');

        $user = User::factory()->activated()->create([
            'applicant_type' => ApplicantType::Individual,
        ]);

        $this->actingAs($user)->post('/applicant/applications', [
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'is_foreign' => false,
            'submitting_for' => 'other',
            'subject_first_name' => 'Mary',
            'subject_last_name' => 'Mwale',
            'gender' => 'female',
            'identity_type' => 'passport',
            'identity_number' => 'P1234567',
            'identity_file' => UploadedFile::fake()->image('passport.png')->size(200),
        ])->assertRedirect();

        $meta = (array) (Application::query()->firstOrFail()->metadata ?? []);

        $this->assertSame(ApplicationNotificationContact::MODE_APPLICANT_ACCOUNT, $meta['notification_contact_mode'] ?? null);
        $this->assertArrayNotHasKey('additional_notification_email', $meta);
    }

    public function test_holder_email_and_phone_not_required_when_applicant_account_contact_selected(): void
    {
        Storage::fake('local');

        $user = User::factory()->activated()->create([
            'applicant_type' => ApplicantType::Individual,
        ]);

        $this->actingAs($user)->post('/applicant/applications', [
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'is_foreign' => false,
            'submitting_for' => 'other',
            'notification_contact_mode' => ApplicationNotificationContact::MODE_APPLICANT_ACCOUNT,
            'subject_first_name' => 'Mary',
            'subject_last_name' => 'Mwale',
            'gender' => 'female',
            'identity_type' => 'passport',
            'identity_number' => 'P1234567',
            'identity_file' => UploadedFile::fake()->image('passport.png')->size(200),
        ])->assertRedirect();

        $subject = (array) ((Application::query()->firstOrFail()->metadata ?? [])['verification_subject'] ?? []);

        $this->assertArrayNotHasKey('email', $subject);
        $this->assertArrayNotHasKey('phone', $subject);
    }

    public function test_additional_email_is_required_when_additional_recipient_option_selected(): void
    {
        Storage::fake('local');

        $user = User::factory()->activated()->create([
            'applicant_type' => ApplicantType::Individual,
        ]);

        $this->actingAs($user)->post('/applicant/applications', [
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'is_foreign' => false,
            'submitting_for' => 'other',
            'notification_contact_mode' => ApplicationNotificationContact::MODE_ADDITIONAL_EMAIL,
            'subject_first_name' => 'Mary',
            'subject_last_name' => 'Mwale',
            'gender' => 'female',
            'identity_type' => 'passport',
            'identity_number' => 'P1234567',
            'identity_file' => UploadedFile::fake()->image('passport.png')->size(200),
        ])->assertSessionHasErrors('additional_notification_email');
    }

    public function test_invalid_additional_email_is_rejected(): void
    {
        Storage::fake('local');

        $user = User::factory()->activated()->create([
            'applicant_type' => ApplicantType::Individual,
        ]);

        $this->actingAs($user)->post('/applicant/applications', [
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'is_foreign' => false,
            'submitting_for' => 'other',
            'notification_contact_mode' => ApplicationNotificationContact::MODE_ADDITIONAL_EMAIL,
            'additional_notification_email' => 'not-an-email',
            'subject_first_name' => 'Mary',
            'subject_last_name' => 'Mwale',
            'gender' => 'female',
            'identity_type' => 'passport',
            'identity_number' => 'P1234567',
            'identity_file' => UploadedFile::fake()->image('passport.png')->size(200),
        ])->assertSessionHasErrors('additional_notification_email');
    }

    public function test_additional_email_is_saved(): void
    {
        Storage::fake('local');

        $user = User::factory()->activated()->create([
            'applicant_type' => ApplicantType::Individual,
        ]);

        $this->actingAs($user)->post('/applicant/applications', [
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'is_foreign' => false,
            'submitting_for' => 'other',
            'notification_contact_mode' => ApplicationNotificationContact::MODE_ADDITIONAL_EMAIL,
            'additional_notification_email' => 'relative@example.com',
            'additional_notification_name' => 'Jane Relative',
            'additional_notification_relationship' => 'parent',
            'subject_first_name' => 'Mary',
            'subject_last_name' => 'Mwale',
            'gender' => 'female',
            'identity_type' => 'passport',
            'identity_number' => 'P1234567',
            'identity_file' => UploadedFile::fake()->image('passport.png')->size(200),
        ])->assertRedirect();

        $meta = (array) (Application::query()->firstOrFail()->metadata ?? []);

        $this->assertSame(ApplicationNotificationContact::MODE_ADDITIONAL_EMAIL, $meta['notification_contact_mode'] ?? null);
        $this->assertSame('relative@example.com', $meta['additional_notification_email'] ?? null);
        $this->assertSame('Jane Relative', $meta['additional_notification_name'] ?? null);
        $this->assertSame('parent', $meta['additional_notification_relationship'] ?? null);
    }

    public function test_self_submission_flow_still_works(): void
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

        $this->actingAs($user)->post('/applicant/applications', [
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'is_foreign' => false,
            'submitting_for' => 'self',
            'gender' => 'female',
            'identity_type' => 'nrc',
            'identity_number' => '888888/88/8',
            'identity_file' => UploadedFile::fake()->image('nrc.png')->size(200),
        ])->assertRedirect();

        $meta = (array) (Application::query()->firstOrFail()->metadata ?? []);

        $this->assertSame('self', $meta['submitting_for'] ?? null);
        $this->assertArrayNotHasKey('notification_contact_mode', $meta);
    }

    public function test_old_applications_without_mode_behave_as_applicant_account_contact(): void
    {
        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-LEGACY-001',
            'applicant_user_id' => User::factory()->activated()->create()->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Draft,
            'is_foreign' => false,
            'metadata' => [
                'submitting_for' => 'other',
                'verification_subject' => ['full_name' => 'Legacy Holder'],
            ],
        ]);

        $contact = ApplicationNotificationContact::fromMetadata((array) ($application->metadata ?? []));

        $this->assertSame(ApplicationNotificationContact::MODE_APPLICANT_ACCOUNT, $contact['mode']);
        $this->assertNull(ApplicationNotificationContact::additionalEmailForOutcome($application));
        $this->assertSame('Applicant account', ApplicationNotificationContact::adminLabel($application));
    }

    public function test_certificate_notification_includes_additional_email_when_configured(): void
    {
        Storage::fake('local');
        Mail::fake();

        [$application, $qualification] = $this->eligiblePaidApprovedQualification([
            'notification_contact_mode' => ApplicationNotificationContact::MODE_ADDITIONAL_EMAIL,
            'additional_notification_email' => 'relative@example.com',
        ]);

        $this->mockPdfLoadView();

        $issuer = User::factory()->activated()->create();
        $issuer->assignRole('Super Admin');

        app(QualificationCertificateService::class)->issue($qualification, $issuer, reissue: false);

        Mail::assertQueued(QualificationCertificateIssuedMail::class, 2);
        Mail::assertQueued(QualificationCertificateIssuedMail::class, fn (QualificationCertificateIssuedMail $mail) => $mail->hasTo($application->applicant->email));
        Mail::assertQueued(QualificationCertificateIssuedMail::class, fn (QualificationCertificateIssuedMail $mail) => $mail->hasTo('relative@example.com'));
    }

    public function test_duplicate_email_is_not_sent_when_additional_equals_applicant_email(): void
    {
        Storage::fake('local');
        Mail::fake();

        [$application, $qualification] = $this->eligiblePaidApprovedQualification();
        $meta = (array) ($application->metadata ?? []);
        $meta['notification_contact_mode'] = ApplicationNotificationContact::MODE_ADDITIONAL_EMAIL;
        $meta['additional_notification_email'] = $application->applicant->email;
        $application->forceFill(['metadata' => $meta])->save();

        $this->mockPdfLoadView();

        $issuer = User::factory()->activated()->create();
        $issuer->assignRole('Super Admin');

        app(QualificationCertificateService::class)->issue($qualification, $issuer, reissue: false);

        Mail::assertQueued(QualificationCertificateIssuedMail::class, 1);
    }

    public function test_application_rejection_sends_outcome_email_to_additional_recipient(): void
    {
        Mail::fake();

        $applicant = User::factory()->activated()->create([
            'email' => 'applicant@example.com',
        ]);

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-REJECT-001',
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::InProgress,
            'verification_state' => VerificationState::UnderLevel2Review,
            'is_foreign' => false,
            'metadata' => [
                'submitting_for' => 'other',
                'notification_contact_mode' => ApplicationNotificationContact::MODE_ADDITIONAL_EMAIL,
                'additional_notification_email' => 'relative@example.com',
            ],
        ]);

        $actor = User::factory()->activated()->create();
        $actor->assignRole('Super Admin');

        app(DecisionService::class)->reject($application, $actor, 'Incomplete documents');

        Mail::assertQueued(ApplicationOutcomeNotificationMail::class, 2);
        Mail::assertQueued(ApplicationOutcomeNotificationMail::class, fn (ApplicationOutcomeNotificationMail $mail) => $mail->hasTo('applicant@example.com'));
        Mail::assertQueued(ApplicationOutcomeNotificationMail::class, fn (ApplicationOutcomeNotificationMail $mail) => $mail->hasTo('relative@example.com'));
    }

    /**
     * @param  array<string, mixed>  $metadataOverrides
     * @return array{Application, Qualification}
     */
    private function eligiblePaidApprovedQualification(array $metadataOverrides = []): array
    {
        $type = QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();
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
            'metadata' => array_merge([
                'submitting_for' => 'other',
            ], $metadataOverrides),
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
        ]);

        $application->refresh()->load('qualifications', 'applicant');
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

        return [$application, $qualification];
    }

    private function mockPdfLoadView(string $output = '%PDF-test-output'): void
    {
        $pdfMock = Mockery::mock(DomPdfWrapper::class);
        $pdfMock->shouldReceive('setPaper')->once()->with('A4', 'portrait')->andReturnSelf();
        $pdfMock->shouldReceive('output')->once()->andReturn($output);

        Pdf::shouldReceive('loadView')
            ->once()
            ->andReturn($pdfMock);
    }
}
