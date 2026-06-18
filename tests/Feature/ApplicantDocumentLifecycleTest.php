<?php

namespace Tests\Feature;

use App\Domain\Applications\ApplicationSubmissionReadinessService;
use App\Enums\ApplicantType;
use App\Enums\DocumentType;
use App\Enums\VerificationState;
use App\Models\ApplicantProfile;
use App\Models\Application;
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

class ApplicantDocumentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BillingCategoriesSeeder::class);
        $this->seed(QualificationTypesSeeder::class);
        $this->seed(FeeStructuresSeeder::class);
    }

    public function test_replacing_document_supersedes_old_version_and_shows_only_latest_to_officers(): void
    {
        [$user, $application, $qualification, $v1] = $this->seedQualificationWithCertificate('certificate-v1.pdf');

        $this->actingAs($user)
            ->post("/applicant/applications/{$application->id}/documents", [
                'document_type' => 'certificate_copy',
                'qualification_id' => $qualification->id,
                'file' => UploadedFile::fake()->create('certificate-v2.pdf', 100, 'application/pdf'),
            ])
            ->assertRedirect();

        $v1->refresh();
        $v2 = QualificationDocument::query()
            ->where('application_id', $application->id)
            ->where('qualification_id', $qualification->id)
            ->where('document_type', DocumentType::CertificateCopy->value)
            ->where('is_current_version', true)
            ->firstOrFail();

        $this->assertFalse($v1->is_current_version);
        $this->assertNotNull($v1->superseded_at);
        $this->assertSame($v2->id, $v1->replaced_by_document_id);
        $this->assertTrue($v2->is_current_version);

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'documents.applicant_document_replaced',
            'entity_id' => $v2->id,
        ]);

        $this->actingAs($user)
            ->get(route('applicant.applications.edit', ['application' => $application]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('application.documents', 1)
                ->where('application.documents.0.id', $v2->id)
            );

        $level2 = User::factory()->activated()->create(['applicant_type' => null]);
        $level2->givePermissionTo(['verification.level2.review', 'verification.pool.view', 'dashboard.view']);
        $qualification->forceFill(['verification_state' => VerificationState::UnderLevel2Review])->save();

        $this->actingAs($level2)
            ->get(route('admin.verification.qualifications.show', ['qualification' => $qualification->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('qualification.documents', 1)
                ->where('qualification.documents.0.id', $v2->id)
            );

        $this->actingAs($level2)
            ->get(route('admin.verification.documents.preview', ['document' => $v1->id]))
            ->assertNotFound();

        $this->actingAs($level2)
            ->get(route('admin.verification.documents.preview', ['document' => $v2->id]))
            ->assertOk();
    }

    public function test_deleting_document_soft_deletes_and_blocks_completeness_until_reupload(): void
    {
        [$user, $application, $qualification, $document] = $this->seedQualificationWithCertificate('certificate.pdf');

        $this->actingAs($user)
            ->delete(route('applicant.documents.destroy', ['document' => $document]))
            ->assertRedirect();

        $document->refresh();
        $this->assertNotNull($document->deleted_at);
        $this->assertFalse($document->is_current_version);

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'documents.applicant_document_deleted',
            'entity_id' => $document->id,
        ]);

        $this->actingAs($user)
            ->get(route('applicant.applications.edit', ['application' => $application]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('application.documents', 0)
                ->where('application.qualifications.0.has_certificate_document', false)
            );

        $level2 = User::factory()->activated()->create(['applicant_type' => null]);
        $level2->givePermissionTo(['verification.level2.review', 'verification.pool.view', 'dashboard.view']);
        $qualification->forceFill(['verification_state' => VerificationState::UnderLevel2Review])->save();

        $this->actingAs($level2)
            ->get(route('admin.verification.qualifications.show', ['qualification' => $qualification->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('qualification.documents', 0));

        $this->actingAs($user)->post("/applicant/applications/{$application->id}/documents", [
            'document_type' => 'nrc_copy',
            'file' => UploadedFile::fake()->image('nrc.png')->size(200),
        ])->assertRedirect();

        $this->actingAs($user)->patch("/applicant/applications/{$application->id}/wizard-declarations", [
            'accept_terms' => true,
            'confirm_information_correct' => true,
        ])->assertRedirect();

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(ApplicationSubmissionReadinessService::class)->assertReadyForPayment($application->fresh(), $user);
    }

    public function test_replacing_transcript_does_not_affect_certificate_document(): void
    {
        [$user, $application, $qualification] = $this->seedQualificationWithCertificate('certificate.pdf');

        $this->actingAs($user)
            ->post("/applicant/applications/{$application->id}/documents", [
                'document_type' => 'transcript',
                'qualification_id' => $qualification->id,
                'file' => UploadedFile::fake()->create('transcript-v1.pdf', 80, 'application/pdf'),
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->post("/applicant/applications/{$application->id}/documents", [
                'document_type' => 'transcript',
                'qualification_id' => $qualification->id,
                'file' => UploadedFile::fake()->create('transcript-v2.pdf', 80, 'application/pdf'),
            ])
            ->assertRedirect();

        $active = QualificationDocument::query()
            ->where('application_id', $application->id)
            ->where('qualification_id', $qualification->id)
            ->whereNull('deleted_at')
            ->where('is_current_version', true)
            ->get();

        $this->assertCount(2, $active);
        $this->assertTrue($active->contains(fn ($d) => $d->document_type === DocumentType::CertificateCopy));
        $this->assertTrue($active->contains(fn ($d) => $d->document_type === DocumentType::Transcript));
    }

    /**
     * @return array{0: User, 1: Application, 2: Qualification, 3: QualificationDocument}
     */
    private function seedQualificationWithCertificate(string $filename): array
    {
        $user = User::factory()->activated()->create(['applicant_type' => ApplicantType::Individual]);
        ApplicantProfile::create([
            'user_id' => $user->id,
            'first_name' => 'Jane',
            'surname' => 'Applicant',
            'gender' => 'female',
            'nrc_number' => '111111/11/1',
            'identity_type' => 'nrc',
            'email' => $user->email,
            'phone_primary' => $user->phone_primary,
            'identity_document_uploaded_at' => now(),
        ]);

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-DOC-'.random_int(1000, 9999),
            'applicant_user_id' => $user->id,
            'applicant_type' => ApplicantType::Individual,
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => 'draft',
            'is_foreign' => false,
            'metadata' => ['submitting_for' => 'self'],
        ]);

        $type = QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();
        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test Institute',
            'qualification_holder_name' => 'Jane Applicant',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'certificate_number' => 'CERT-001',
            'title_of_qualification' => 'Diploma in Testing',
            'names_as_on_qualification_document' => 'Jane Applicant',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ]);

        $this->actingAs($user)
            ->post("/applicant/applications/{$application->id}/documents", [
                'document_type' => 'certificate_copy',
                'qualification_id' => $qualification->id,
                'file' => UploadedFile::fake()->create($filename, 100, 'application/pdf'),
            ])
            ->assertRedirect();

        $document = QualificationDocument::query()
            ->where('application_id', $application->id)
            ->where('qualification_id', $qualification->id)
            ->where('document_type', DocumentType::CertificateCopy->value)
            ->where('is_current_version', true)
            ->firstOrFail();

        return [$user, $application, $qualification, $document];
    }
}
