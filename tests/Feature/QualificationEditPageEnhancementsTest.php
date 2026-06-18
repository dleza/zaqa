<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\DocumentType;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\Qualification;
use App\Models\QualificationDocument;
use App\Models\QualificationType;
use App\Models\User;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\FeeStructuresSeeder;
use Database\Seeders\QualificationTypesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class QualificationEditPageEnhancementsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed([BillingCategoriesSeeder::class, QualificationTypesSeeder::class, FeeStructuresSeeder::class]);
    }

    private function makeApplication(): Application
    {
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        return Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-EDT-'.Str::upper(Str::random(6)),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Submitted,
            'verification_state' => VerificationState::UnderLevel2Review,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
            'paid_at' => now(),
        ]);
    }

    private function makeQualification(Application $application, array $overrides = []): Qualification
    {
        $type = QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();
        $country = Country::query()->firstOrCreate(
            ['iso_code' => 'ZMB'],
            ['name' => 'Zambia', 'is_active' => true, 'sort_order' => 1],
        );
        $inst = AwardingInstitution::query()->firstOrCreate(
            ['country_id' => $country->id, 'name' => 'Test University'],
            ['is_active' => true, 'sort_order' => 1],
        );

        return Qualification::query()->create(array_merge([
            'application_id' => $application->id,
            'country_id' => $country->id,
            'awarding_institution_id' => $inst->id,
            'awarding_institution_name' => $inst->name,
            'qualification_holder_name' => 'Jane Doe',
            'names_as_on_qualification_document' => 'JANE DOE',
            'country_name_other' => null,
            'nrc_passport_number' => '111111/11/1',
            'certificate_number' => 'CERT-ORIG',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'verification_state' => VerificationState::UnderLevel2Review,
        ], $overrides));
    }

    private function basePayload(Qualification $qualification): array
    {
        return [
            'qualification_holder_name' => $qualification->qualification_holder_name,
            'names_as_on_qualification_document' => $qualification->names_as_on_qualification_document,
            'nrc_passport_number' => $qualification->nrc_passport_number,
            'country_id' => $qualification->country_id,
            'country_name_other' => $qualification->country_name_other,
            'awarding_institution_id' => $qualification->awarding_institution_id,
            'awarding_institution_name_other' => null,
            'awarding_institution_name' => $qualification->awarding_institution_name,
            'certificate_number' => $qualification->certificate_number,
            'student_number' => null,
            'examination_number' => null,
            'title_of_qualification' => $qualification->title_of_qualification,
            'award_date' => $qualification->award_date?->format('Y-m-d'),
            'qualification_type_id' => $qualification->qualification_type_id,
            'subject_results' => [],
        ];
    }

    private function makeLevel2Officer(): User
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->assignRole('Verification Officer Level 2');
        $user->givePermissionTo([
            'verification.pool.view',
            'verification.level2.review',
            'verification.decide.approve',
            'verification.decide.reject',
            'verification.certificate.issue',
        ]);

        return $user;
    }

    private function makeLevel1Officer(): User
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->assignRole('Verification Officer Level 1');
        $user->givePermissionTo(['verification.pool.view', 'verification.level1.process']);

        return $user;
    }

    public function test_save_without_correction_note_is_rejected_when_fields_change(): void
    {
        $qualification = $this->makeQualification($this->makeApplication());
        $l2 = $this->makeLevel2Officer();

        $payload = $this->basePayload($qualification);
        $payload['qualification_holder_name'] = 'Jane D. Corrected';

        $this->actingAs($l2)
            ->put(route('admin.verification.qualifications.update', $qualification), $payload)
            ->assertSessionHasErrors(['correction_note']);
    }

    public function test_save_with_correction_note_succeeds_and_records_audit_note(): void
    {
        $qualification = $this->makeQualification($this->makeApplication());
        $l2 = $this->makeLevel2Officer();

        $payload = $this->basePayload($qualification);
        $payload['qualification_holder_name'] = 'Jane D. Corrected';
        $payload['correction_note'] = 'Fixed holder name to match certificate.';

        $this->actingAs($l2)
            ->put(route('admin.verification.qualifications.update', $qualification), $payload)
            ->assertRedirect(route('admin.verification.qualifications.show', $qualification));

        $audit = AuditLog::query()
            ->where('event_type', 'verification.qualification_corrected')
            ->where('entity_id', $qualification->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame('Fixed holder name to match certificate.', $audit->metadata['correction_note'] ?? null);
    }

    public function test_save_without_changes_redirects_with_info_and_without_note(): void
    {
        $qualification = $this->makeQualification($this->makeApplication());
        $l2 = $this->makeLevel2Officer();

        $this->actingAs($l2)
            ->put(route('admin.verification.qualifications.update', $qualification), $this->basePayload($qualification))
            ->assertRedirect(route('admin.verification.qualifications.edit', $qualification))
            ->assertSessionHas('info', 'No changes to save.');
    }

    public function test_level2_edit_page_includes_decision_permissions_when_eligible(): void
    {
        $l2 = $this->makeLevel2Officer();
        $qualification = $this->makeQualification($this->makeApplication(), [
            'level2_review_owner_id' => $l2->id,
        ]);

        $this->actingAs($l2)
            ->get(route('admin.verification.qualifications.edit', $qualification))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can.approve', true)
                ->where('can.reject', true)
                ->where('qualification.verification_state', VerificationState::UnderLevel2Review->value));
    }

    public function test_level1_edit_page_does_not_include_level2_decision_permissions(): void
    {
        $l1 = $this->makeLevel1Officer();
        $qualification = $this->makeQualification($this->makeApplication(), [
            'verification_state' => VerificationState::UnderLevel1Review,
            'assigned_verifier_id' => $l1->id,
        ]);

        $this->actingAs($l1)
            ->get(route('admin.verification.qualifications.edit', $qualification))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can.approve', false)
                ->where('can.reject', false));
    }

    public function test_level2_edit_page_excludes_decision_permissions_when_not_in_reviewable_state(): void
    {
        $l2 = $this->makeLevel2Officer();
        $qualification = $this->makeQualification($this->makeApplication(), [
            'verification_state' => VerificationState::ApprovedForCertificate,
        ]);

        $this->actingAs($l2)
            ->get(route('admin.verification.qualifications.edit', $qualification))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can.approve', true)
                ->where('can.reject', true)
                ->where('qualification.verification_state', VerificationState::ApprovedForCertificate->value));
    }

    public function test_approve_from_edit_page_uses_same_route_as_review_page(): void
    {
        $l2 = $this->makeLevel2Officer();
        $qualification = $this->makeQualification($this->makeApplication(), [
            'level2_review_owner_id' => $l2->id,
        ]);

        $this->actingAs($l2)
            ->post(route('admin.verification.qualifications.approve', $qualification), [
                'findings' => 'Level 1 findings for review.',
                'accreditation_statement' => 'Accredited institution statement.',
                'comment' => 'Looks good.',
                'issue_certificate' => false,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $qualification->refresh();
        $this->assertSame(VerificationState::ApprovedForCertificate, $qualification->verification_state);
    }

    public function test_level2_cannot_replace_or_delete_applicant_uploaded_documents_on_edit_page(): void
    {
        [$application, $qualification, $document] = $this->seedApplicantCertificateDocument();
        $l2 = $this->makeLevel2Officer();
        $qualification->forceFill([
            'verification_state' => VerificationState::UnderLevel2Review,
            'level2_review_owner_id' => $l2->id,
        ])->save();

        $this->actingAs($l2)
            ->get(route('admin.verification.qualifications.edit', $qualification))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('documents', 1)
                ->where('documents.0.id', $document->id)
                ->where('documents.0.can_delete', false)
                ->where('documents.0.can_replace', false));

        $this->actingAs($l2)
            ->post(route('admin.verification.qualifications.documents.store', $qualification), [
                'document_type' => DocumentType::CertificateCopy->value,
                'file' => UploadedFile::fake()->create('verifier-replacement.pdf', 100, 'application/pdf'),
                'correction_note' => 'Attempted replace',
            ])
            ->assertSessionHasErrors(['file']);

        $this->actingAs($l2)
            ->delete(route('admin.verification.qualifications.documents.destroy', [
                'qualification' => $qualification,
                'document' => $document,
            ]))
            ->assertSessionHasErrors(['file']);

        $document->refresh();
        $this->assertNull($document->deleted_at);
        $this->assertTrue($document->is_current_version);
    }

    public function test_level1_cannot_replace_or_delete_applicant_uploaded_documents_on_edit_page(): void
    {
        [$application, $qualification, $document] = $this->seedApplicantCertificateDocument();
        $l1 = $this->makeLevel1Officer();
        $qualification->forceFill([
            'verification_state' => VerificationState::UnderLevel1Review,
            'assigned_verifier_id' => $l1->id,
        ])->save();

        $this->actingAs($l1)
            ->get(route('admin.verification.qualifications.edit', $qualification))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('documents.0.can_delete', false)
                ->where('documents.0.can_replace', false));

        $this->actingAs($l1)
            ->delete(route('admin.verification.qualifications.documents.destroy', [
                'qualification' => $qualification,
                'document' => $document,
            ]))
            ->assertSessionHasErrors(['file']);
    }

    public function test_super_admin_can_replace_applicant_uploaded_documents_on_edit_page(): void
    {
        [$application, $qualification, $document] = $this->seedApplicantCertificateDocument();
        $superAdmin = User::factory()->activated()->create(['applicant_type' => null]);
        $superAdmin->assignRole('Super Admin');
        $qualification->forceFill(['verification_state' => VerificationState::UnderLevel2Review])->save();

        $this->actingAs($superAdmin)
            ->get(route('admin.verification.qualifications.edit', $qualification))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('documents.0.can_delete', true)
                ->where('documents.0.can_replace', true));

        $this->actingAs($superAdmin)
            ->post(route('admin.verification.qualifications.documents.store', $qualification), [
                'document_type' => DocumentType::CertificateCopy->value,
                'file' => UploadedFile::fake()->create('admin-replacement.pdf', 100, 'application/pdf'),
                'correction_note' => 'Super Admin correction',
            ])
            ->assertRedirect(route('admin.verification.qualifications.edit', $qualification))
            ->assertSessionHasNoErrors();

        $document->refresh();
        $this->assertFalse($document->is_current_version);
    }

    /**
     * @return array{0: Application, 1: Qualification, 2: QualificationDocument}
     */
    private function seedApplicantCertificateDocument(): array
    {
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-DOC-'.Str::upper(Str::random(6)),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Submitted,
            'verification_state' => VerificationState::UnderLevel2Review,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
            'paid_at' => now(),
        ]);

        $qualification = $this->makeQualification($application);
        $path = sprintf('applications/%s/certificate_copy_v1_test.pdf', $application->id);
        Storage::disk('local')->put($path, 'certificate');

        $document = QualificationDocument::query()->create([
            'application_id' => $application->id,
            'qualification_id' => $qualification->id,
            'document_type' => DocumentType::CertificateCopy->value,
            'original_name' => 'certificate.pdf',
            'stored_name' => 'certificate_copy_v1_test.pdf',
            'disk' => 'local',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 100,
            'sha256_hash' => hash('sha256', 'certificate'),
            'visibility' => 'private',
            'uploaded_by_user_id' => $applicant->id,
            'version_number' => 1,
            'is_current_version' => true,
        ]);

        return [$application, $qualification, $document];
    }
}
