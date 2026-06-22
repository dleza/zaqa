<?php

namespace Tests\Feature;

use App\Domain\Finance\InvoicePdfService;
use App\Enums\ApplicantType;
use App\Enums\DocumentType;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\InstitutionProfile;
use App\Models\Qualification;
use App\Models\QualificationDocument;
use App\Models\QualificationType;
use App\Models\User;
use App\Support\Applications\ApplicationSubmissionMode;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\FeeStructuresSeeder;
use Database\Seeders\QualificationTypesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class InstitutionalMultipleApplicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BillingCategoriesSeeder::class);
        $this->seed(QualificationTypesSeeder::class);
        $this->seed(FeeStructuresSeeder::class);
    }

    public function test_institution_can_access_multiple_applications_flow(): void
    {
        $user = $this->makeInstitutionUser();

        $this->actingAs($user)
            ->get(route('applicant.applications.multiple.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Applicant/Applications/Multiple/New'));

        $this->actingAs($user)
            ->post(route('applicant.applications.multiple.store'))
            ->assertRedirect();

        $application = Application::query()->where('applicant_user_id', $user->id)->latest('id')->first();
        $this->assertNotNull($application);
        $this->assertTrue(ApplicationSubmissionMode::isInstitutionalMultiple($application));

        $this->actingAs($user)
            ->get(route('applicant.applications.multiple.edit', $application))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Applicant/Applications/Multiple/Edit'));

        $this->actingAs($user)
            ->patch(route('applicant.applications.multiple.update', $application), [
                'notification_contact_email' => 'backup@institution.test',
            ])
            ->assertRedirect(route('applicant.applications.multiple.edit', [
                'application' => $application,
                'step' => 'qualification_records',
            ]))
            ->assertSessionHas('success');

        $application->refresh();
        $this->assertSame('backup@institution.test', $application->metadata['notification_contact_email'] ?? null);
        $this->assertTrue(ApplicationSubmissionMode::isInstitutionalMultiple($application));

        $this->actingAs($user)
            ->get(route('applicant.applications.multiple.edit', ['application' => $application, 'step' => 'qualification_records']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Applicant/Applications/Multiple/Edit')
                ->where('initial_step', 'qualification_records')
            );
    }

    public function test_individual_cannot_access_multiple_applications_flow(): void
    {
        $user = User::factory()->activated()->create([
            'applicant_type' => ApplicantType::Individual,
        ]);

        $this->actingAs($user)
            ->get(route('applicant.applications.multiple.create'))
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('applicant.applications.multiple.store'))
            ->assertForbidden();
    }

    public function test_multiple_qualification_holders_persist_per_qualification(): void
    {
        Storage::fake('local');
        [$user, $application, $zambia, $institution, $type] = $this->makeInstitutionalDraft();

        $this->actingAs($user)->post(route('applicant.applications.multiple.qualifications.store', $application), $this->qualPayload($zambia, $institution, $type, [
            'holder_first_name' => 'Alice',
            'holder_surname' => 'Alpha',
            'nrc_passport_number' => '111111/11/1',
            'names_as_on_qualification_document' => 'Alice Alpha',
            'title_of_qualification' => 'Diploma A',
        ]))->assertRedirect();

        $q1 = Qualification::query()->where('application_id', $application->id)->firstOrFail();
        $this->uploadQualDocuments($user, $application, $q1);

        $this->actingAs($user)->post(route('applicant.applications.multiple.qualifications.store', $application), $this->qualPayload($zambia, $institution, $type, [
            'holder_first_name' => 'Brian',
            'holder_surname' => 'Beta',
            'nrc_passport_number' => '222222/22/2',
            'names_as_on_qualification_document' => 'Brian Beta',
            'title_of_qualification' => 'Diploma B',
        ]))->assertRedirect();

        $quals = Qualification::query()->where('application_id', $application->id)->orderBy('id')->get();
        $this->assertCount(2, $quals);
        $this->assertSame('Alice Alpha', $quals[0]->qualification_holder_name);
        $this->assertSame('111111/11/1', $quals[0]->nrc_passport_number);
        $this->assertSame('Brian Beta', $quals[1]->qualification_holder_name);
        $this->assertSame('222222/22/2', $quals[1]->nrc_passport_number);
    }

    public function test_readiness_requires_per_qualification_identity_and_certificate(): void
    {
        Storage::fake('local');
        [$user, $application, $zambia, $institution, $type] = $this->makeInstitutionalDraft();

        $this->actingAs($user)->post(route('applicant.applications.multiple.qualifications.store', $application), $this->qualPayload($zambia, $institution, $type, [
            'holder_first_name' => 'Alice',
            'holder_surname' => 'Alpha',
            'nrc_passport_number' => '111111/11/1',
            'names_as_on_qualification_document' => 'Alice Alpha',
        ]));

        $readiness = app(\App\Domain\Applications\ApplicationSubmissionReadinessService::class);
        $application->refresh()->load('qualifications', 'documents', 'applicant');

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $readiness->assertReadyForPayment($application, $user);
    }

    public function test_transcript_remains_optional_for_local_qualification(): void
    {
        Storage::fake('local');
        [$user, $application, $zambia, $institution, $type] = $this->makeInstitutionalDraft();

        $this->actingAs($user)->post(route('applicant.applications.multiple.qualifications.store', $application), $this->qualPayload($zambia, $institution, $type, [
            'holder_first_name' => 'Alice',
            'holder_surname' => 'Alpha',
            'nrc_passport_number' => '111111/11/1',
            'names_as_on_qualification_document' => 'Alice Alpha',
        ]));

        $q = Qualification::query()->where('application_id', $application->id)->firstOrFail();
        $this->uploadQualDocuments($user, $application, $q, includeTranscript: false);

        $application->refresh()->load('qualifications', 'documents', 'applicant');
        $application->forceFill([
            'metadata' => array_merge((array) $application->metadata, [
                'wizard_declarations' => [
                    'terms_accepted_at' => now()->toIso8601String(),
                    'information_confirmed_at' => now()->toIso8601String(),
                ],
            ]),
        ])->save();

        app(\App\Domain\Applications\ApplicationSubmissionReadinessService::class)
            ->assertReadyForPayment($application->fresh(['qualifications', 'documents', 'applicant']), $user);

        $this->assertFalse((bool) $q->fresh()->transcript_required);
    }

    public function test_invoice_line_items_include_holder_names_for_institutional_multiple(): void
    {
        Storage::fake('local');
        [$user, $application, $zambia, $institution, $type] = $this->makeInstitutionalDraft();

        foreach ([
            ['Alice', 'Alpha', '111111/11/1', 'Diploma A'],
            ['Brian', 'Beta', '222222/22/2', 'Diploma B'],
        ] as [$first, $surname, $nrc, $title]) {
            $this->actingAs($user)->post(route('applicant.applications.multiple.qualifications.store', $application), $this->qualPayload($zambia, $institution, $type, [
                'holder_first_name' => $first,
                'holder_surname' => $surname,
                'nrc_passport_number' => $nrc,
                'names_as_on_qualification_document' => "{$first} {$surname}",
                'title_of_qualification' => $title,
            ]));
            $q = Qualification::query()->where('application_id', $application->id)->where('title_of_qualification', $title)->firstOrFail();
            $this->uploadQualDocuments($user, $application, $q);
        }

        $application->refresh()->load('qualifications', 'documents', 'applicant');
        $application->forceFill([
            'metadata' => array_merge((array) $application->metadata, [
                'wizard_declarations' => [
                    'terms_accepted_at' => now()->toIso8601String(),
                    'information_confirmed_at' => now()->toIso8601String(),
                ],
            ]),
        ])->save();

        $invoice = app(\App\Domain\Payments\InvoiceService::class)->ensureInvoice($application->fresh(), $user);
        $lines = app(InvoicePdfService::class)->lineItems($invoice->fresh('application.qualifications'))->pluck('description')->all();

        $this->assertCount(2, $lines);
        $this->assertStringContainsString('Diploma A', $lines[0]);
        $this->assertStringContainsString('Alice Alpha', $lines[0]);
        $this->assertStringContainsString('Diploma B', $lines[1]);
        $this->assertStringContainsString('Brian Beta', $lines[1]);
    }

    public function test_application_show_includes_applicant_friendly_institutional_overview(): void
    {
        [$user, $application, $zambia, $institution, $type] = $this->makeInstitutionalDraft();

        Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_id' => $institution->id,
            'awarding_institution_name' => $institution->name,
            'qualification_holder_name' => 'Review Holder',
            'country_id' => $zambia->id,
            'nrc_passport_number' => '121212/12/1',
            'title_of_qualification' => 'Review Diploma',
            'names_as_on_qualification_document' => 'Review Holder',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'verification_state' => VerificationState::UnderLevel1Review,
        ]);

        Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_id' => $institution->id,
            'awarding_institution_name' => $institution->name,
            'qualification_holder_name' => 'Returned Holder',
            'country_id' => $zambia->id,
            'nrc_passport_number' => '131313/13/1',
            'title_of_qualification' => 'Returned Diploma',
            'names_as_on_qualification_document' => 'Returned Holder',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'verification_state' => VerificationState::ReturnedToApplicant,
        ]);

        Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_id' => $institution->id,
            'awarding_institution_name' => $institution->name,
            'qualification_holder_name' => 'Completed Holder',
            'country_id' => $zambia->id,
            'nrc_passport_number' => '141414/14/1',
            'title_of_qualification' => 'Completed Diploma',
            'names_as_on_qualification_document' => 'Completed Holder',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'verification_state' => VerificationState::CertificateIssued,
        ]);

        $this->actingAs($user)
            ->get(route('applicant.applications.show', $application))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('institutional_overview.total_qualifications', 3)
                ->where('institutional_overview.in_review', 1)
                ->where('institutional_overview.returned_for_correction', 1)
                ->where('institutional_overview.completed', 1)
            );
    }

    public function test_admin_assignment_queue_uses_qualification_level_holder(): void
    {
        [$user, $application, $zambia, $institution, $type] = $this->makeInstitutionalDraft();

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_id' => $institution->id,
            'awarding_institution_name' => $institution->name,
            'qualification_holder_name' => 'Queue Holder',
            'country_id' => $zambia->id,
            'nrc_passport_number' => '333333/33/3',
            'certificate_number' => 'CERT-Q',
            'title_of_qualification' => 'Queue Diploma',
            'names_as_on_qualification_document' => 'Queue Holder',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'verification_state' => VerificationState::AwaitingAssignment,
        ]);

        $qualification->load('application.applicant.institutionProfile');
        $row = (new class {
            use \App\Http\Controllers\Admin\Verification\Concerns\MapsVerificationAssignmentQueueRows;

            public function map(Qualification $q): array
            {
                return $this->mapVerificationAssignmentQueueRow($q);
            }
        })->map($qualification);

        $this->assertSame('Queue Holder', $row['holder_name']);
        $this->assertSame('Test Institution Ltd', $row['applicant_name']);
    }

    public function test_individual_standard_flow_still_uses_application_level_holder_on_qual_save(): void
    {
        $user = User::factory()->activated()->create(['applicant_type' => ApplicantType::Individual]);
        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-IND-1',
            'applicant_user_id' => $user->id,
            'applicant_type' => ApplicantType::Individual,
            'service_type' => 'verification',
            'qualification_category' => 'test',
            'current_status' => 'draft',
            'is_foreign' => false,
            'metadata' => [
                'submitting_for' => 'other',
                'verification_subject' => [
                    'full_name' => 'Subject Person',
                    'nrc_number' => '999999/99/9',
                    'identity_type' => 'nrc',
                ],
            ],
        ]);

        $zambia = Country::query()->create(['iso_code' => 'ZMB', 'name' => 'Zambia', 'is_active' => true, 'sort_order' => 0]);
        $institution = AwardingInstitution::query()->create(['country_id' => $zambia->id, 'name' => 'Local Uni', 'is_active' => true, 'sort_order' => 0]);
        $type = QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();

        $this->actingAs($user)->put(route('applicant.applications.qualification.upsert', $application), [
            'awarding_institution_id' => $institution->id,
            'awarding_institution_name' => $institution->name,
            'country_id' => $zambia->id,
            'qualification_holder_name' => 'Ignored Name',
            'nrc_passport_number' => '000000/00/0',
            'certificate_number' => 'CERT-1',
            'title_of_qualification' => 'Individual Diploma',
            'names_as_on_qualification_document' => 'Subject Person',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type_id' => $type->id,
            'create_new' => true,
        ])->assertRedirect();

        $qual = Qualification::query()->where('application_id', $application->id)->firstOrFail();
        $this->assertSame('Subject Person', $qual->qualification_holder_name);
        $this->assertSame('999999/99/9', $qual->nrc_passport_number);
    }

    public function test_send_back_correction_route_for_institutional_qualification(): void
    {
        [$user, $application, $zambia, $institution, $type] = $this->makeInstitutionalDraft();
        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_id' => $institution->id,
            'awarding_institution_name' => $institution->name,
            'qualification_holder_name' => 'Returned Holder',
            'country_id' => $zambia->id,
            'nrc_passport_number' => '444444/44/4',
            'certificate_number' => 'CERT-R',
            'title_of_qualification' => 'Returned Diploma',
            'names_as_on_qualification_document' => 'Returned Holder',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'verification_state' => VerificationState::ReturnedToApplicant,
        ]);

        $this->actingAs($user)
            ->get(route('applicant.applications.qualifications.amend', [
                'application' => $application,
                'qualification' => $qualification,
            ]))
            ->assertRedirect(route('applicant.applications.multiple.qualifications.edit', [
                'application' => $application,
                'qualification' => $qualification,
            ]));
    }

    public function test_l1_qualification_page_shows_qualification_level_holder(): void
    {
        [$user, $application, $zambia, $institution, $type] = $this->makeInstitutionalDraft();
        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_id' => $institution->id,
            'awarding_institution_name' => $institution->name,
            'qualification_holder_name' => 'Level One Holder',
            'country_id' => $zambia->id,
            'nrc_passport_number' => '777777/77/7',
            'certificate_number' => 'CERT-L1',
            'title_of_qualification' => 'Level One Diploma',
            'names_as_on_qualification_document' => 'Level One Holder',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'verification_state' => VerificationState::UnderLevel1Review,
        ]);

        $admin = User::factory()->activated()->create();
        $admin->givePermissionTo(['verification.level1.process', 'verification.pool.view', 'dashboard.view']);
        $qualification->forceFill(['assigned_verifier_id' => $admin->id])->save();

        $this->actingAs($admin)
            ->get(route('admin.verification.qualifications.show', ['qualification' => $qualification]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('qualification.holder_name', 'Level One Holder')
                ->where('qualification.holder_nrc_passport', '777777/77/7')
            );
    }

    public function test_auto_verification_matching_uses_qualification_level_identity(): void
    {
        [$user, $application, $zambia, $institution, $type] = $this->makeInstitutionalDraft();

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_id' => $institution->id,
            'awarding_institution_name' => $institution->name,
            'qualification_holder_name' => 'Auto Match Holder',
            'country_id' => $zambia->id,
            'nrc_passport_number' => '888888/88/8',
            'certificate_number' => 'CERT-AUTO',
            'title_of_qualification' => 'Auto Match Diploma',
            'names_as_on_qualification_document' => 'Auto Match Holder',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'verification_state' => VerificationState::AwaitingAutoVerification,
        ]);

        $this->assertSame('Auto Match Holder', $qualification->qualification_holder_name);
        $this->assertSame('888888/88/8', $qualification->nrc_passport_number);
        $this->assertTrue(
            ApplicationSubmissionMode::isInstitutionalMultiple($application->fresh())
        );
    }

    /**
     * @return array{0: User, 1: Application, 2: Country, 3: AwardingInstitution, 4: QualificationType}
     */
    private function makeInstitutionalDraft(): array
    {
        $user = $this->makeInstitutionUser();

        $this->actingAs($user)->post(route('applicant.applications.multiple.store'))->assertRedirect();
        $application = Application::query()->where('applicant_user_id', $user->id)->latest('id')->firstOrFail();

        $zambia = Country::query()->create(['iso_code' => 'ZMB', 'name' => 'Zambia', 'is_active' => true, 'sort_order' => 0]);
        $institution = AwardingInstitution::query()->create(['country_id' => $zambia->id, 'name' => 'Award College', 'is_active' => true, 'sort_order' => 0]);
        $type = QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();

        return [$user, $application, $zambia, $institution, $type];
    }

    private function makeInstitutionUser(): User
    {
        $user = User::factory()->activated()->create([
            'applicant_type' => ApplicantType::Institution,
            'name' => 'Test Institution Ltd',
        ]);

        InstitutionProfile::create([
            'user_id' => $user->id,
            'institution_name' => 'Test Institution Ltd',
            'email' => $user->email,
            'phone_primary' => $user->phone_primary,
            'tpin' => '1000000000',
        ]);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function qualPayload(Country $zambia, AwardingInstitution $institution, QualificationType $type, array $overrides = []): array
    {
        return array_merge([
            'holder_first_name' => 'Test',
            'holder_surname' => 'Holder',
            'nrc_passport_number' => '555555/55/5',
            'holder_identity_type' => 'nrc',
            'awarding_institution_id' => $institution->id,
            'awarding_institution_name' => $institution->name,
            'country_id' => $zambia->id,
            'certificate_number' => 'CERT-'.Str::upper(Str::random(4)),
            'title_of_qualification' => 'Test Diploma',
            'names_as_on_qualification_document' => 'Test Holder',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type_id' => $type->id,
            'create_new' => true,
        ], $overrides);
    }

    private function uploadQualDocuments(User $user, Application $application, Qualification $qualification, bool $includeTranscript = false): void
    {
        $this->actingAs($user)->post("/applicant/applications/{$application->id}/documents", [
            'document_type' => 'certificate_copy',
            'qualification_id' => $qualification->id,
            'file' => UploadedFile::fake()->create('certificate.pdf', 120, 'application/pdf'),
        ])->assertRedirect();

        $this->actingAs($user)->post("/applicant/applications/{$application->id}/documents", [
            'document_type' => 'nrc_copy',
            'qualification_id' => $qualification->id,
            'file' => UploadedFile::fake()->create('nrc.pdf', 120, 'application/pdf'),
        ])->assertRedirect();

        if ($includeTranscript) {
            $this->actingAs($user)->post("/applicant/applications/{$application->id}/documents", [
                'document_type' => 'transcript',
                'qualification_id' => $qualification->id,
                'file' => UploadedFile::fake()->create('transcript.pdf', 120, 'application/pdf'),
            ])->assertRedirect();
        }
    }
}
