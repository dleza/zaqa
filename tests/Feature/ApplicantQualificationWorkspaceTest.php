<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\QualificationTitleSource;
use App\Models\Application;
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
use Illuminate\Support\Str;
use Tests\TestCase;

class ApplicantQualificationWorkspaceTest extends TestCase
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

    public function test_edit_workspace_includes_other_title_fields_in_payload(): void
    {
        [$user, $application, $qualification] = $this->makeQualificationWithOtherTitle();

        $this->actingAs($user)
            ->get(route('applicant.applications.qualifications.workspace.edit', [
                'application' => $application,
                'qualification' => $qualification,
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Applicant/Applications/Qualifications/Workspace')
                ->where('qualificationId', $qualification->id)
                ->has('application.qualifications', 1)
                ->where('application.qualifications.0.qualification_title_source', 'other')
                ->where('application.qualifications.0.applicant_entered_qualification_title', 'Custom Manual Title')
                ->where('application.qualifications.0.title_of_qualification', 'Custom Manual Title')
                ->where('application.qualifications.0.awarding_institution_id', null)
                ->where('application.qualifications.0.awarding_institution_name_other', 'Unlisted Local College')
            );
    }

    public function test_applicant_can_delete_qualification_scoped_document(): void
    {
        [$user, $application, $qualification] = $this->makeQualificationWithOtherTitle();

        $document = QualificationDocument::query()->create([
            'application_id' => $application->id,
            'qualification_id' => $qualification->id,
            'document_type' => DocumentType::CertificateCopy->value,
            'original_name' => 'certificate.pdf',
            'stored_name' => 'certificate.pdf',
            'disk' => 'local',
            'path' => 'applications/'.$application->id.'/certificate.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 1,
            'sha256_hash' => hash('sha256', 'certificate'),
            'visibility' => 'private',
            'uploaded_by_user_id' => $user->id,
            'version_number' => 1,
            'is_current_version' => true,
        ]);

        $this->actingAs($user)
            ->delete(route('applicant.documents.destroy', ['document' => $document]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('qualification_documents', ['id' => $document->id]);
    }

    /**
     * @return array{0: User, 1: Application, 2: Qualification}
     */
    private function makeQualificationWithOtherTitle(): array
    {
        $user = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-WS-'.random_int(1000, 9999),
            'applicant_user_id' => $user->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => 'draft',
            'is_foreign' => false,
            'metadata' => [],
        ]);

        $country = Country::query()->firstOrCreate(
            ['iso_code' => 'ZMB'],
            ['name' => 'Zambia', 'is_active' => true, 'sort_order' => 1],
        );

        $type = QualificationType::query()->where('name', 'like', '%Diploma%')->first()
            ?? QualificationType::query()->firstOrFail();

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'country_id' => $country->id,
            'awarding_institution_id' => null,
            'awarding_institution_name_other' => 'Unlisted Local College',
            'awarding_institution_name' => 'Unlisted Local College',
            'qualification_holder_name' => 'Jane Applicant',
            'nrc_passport_number' => 'AB1234567',
            'certificate_number' => 'CERT-WS-001',
            'title_of_qualification' => 'Custom Manual Title',
            'names_as_on_qualification_document' => 'Jane Applicant',
            'qualification_title_id' => null,
            'qualification_title_source' => QualificationTitleSource::Other,
            'applicant_entered_qualification_title' => 'Custom Manual Title',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'diploma',
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
        ]);

        return [$user, $application, $qualification];
    }
}
