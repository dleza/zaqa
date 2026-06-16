<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\Qualification;
use App\Models\QualificationTitle;
use App\Models\QualificationType;
use App\Models\User;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\FeeStructuresSeeder;
use Database\Seeders\QualificationTypesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class QualificationDocumentNamesTest extends TestCase
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

    public function test_applicant_cannot_save_qualification_without_document_names(): void
    {
        $user = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $application = $this->makeApplication($user);
        [$inst] = $this->makeInstitutionPair();
        $type = $this->diplomaQualificationType();

        $this->actingAs($user)->post("/applicant/applications/{$application->id}/qualifications", [
            'country_id' => $inst->country_id,
            'awarding_institution_id' => $inst->id,
            'awarding_institution_name' => $inst->name,
            'qualification_type_id' => $type->id,
            'title_of_qualification' => 'Diploma in Testing',
            'qualification_title_source' => 'other',
            'applicant_entered_qualification_title' => 'Diploma in Testing',
            'award_date' => now()->subYear()->toDateString(),
            'certificate_number' => 'CERT-NAMES-001',
        ])->assertSessionHasErrors('names_as_on_qualification_document');
    }

    public function test_applicant_can_save_qualification_with_document_names(): void
    {
        $user = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $application = $this->makeApplication($user);
        [$inst] = $this->makeInstitutionPair();
        $type = $this->diplomaQualificationType();

        $this->actingAs($user)->post("/applicant/applications/{$application->id}/qualifications", [
            'country_id' => $inst->country_id,
            'awarding_institution_id' => $inst->id,
            'awarding_institution_name' => $inst->name,
            'qualification_type_id' => $type->id,
            'title_of_qualification' => 'Diploma in Testing',
            'qualification_title_source' => 'other',
            'applicant_entered_qualification_title' => 'Diploma in Testing',
            'names_as_on_qualification_document' => 'Mary C. Mwansa',
            'award_date' => now()->subYear()->toDateString(),
            'certificate_number' => 'CERT-NAMES-002',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $qualification = Qualification::query()->where('application_id', $application->id)->firstOrFail();
        $this->assertSame('Mary C. Mwansa', $qualification->names_as_on_qualification_document);
    }

    public function test_document_names_are_trimmed_before_save(): void
    {
        $user = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $application = $this->makeApplication($user);
        [$inst] = $this->makeInstitutionPair();
        $type = $this->diplomaQualificationType();

        $this->actingAs($user)->post("/applicant/applications/{$application->id}/qualifications", [
            'country_id' => $inst->country_id,
            'awarding_institution_id' => $inst->id,
            'awarding_institution_name' => $inst->name,
            'qualification_type_id' => $type->id,
            'title_of_qualification' => 'Diploma in Testing',
            'qualification_title_source' => 'other',
            'applicant_entered_qualification_title' => 'Diploma in Testing',
            'names_as_on_qualification_document' => '  C. Lombe  ',
            'award_date' => now()->subYear()->toDateString(),
            'certificate_number' => 'CERT-NAMES-003',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $qualification = Qualification::query()->where('application_id', $application->id)->firstOrFail();
        $this->assertSame('C. Lombe', $qualification->names_as_on_qualification_document);
    }

    public function test_document_names_longer_than_255_characters_are_rejected(): void
    {
        $user = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $application = $this->makeApplication($user);
        [$inst] = $this->makeInstitutionPair();
        $type = $this->diplomaQualificationType();

        $this->actingAs($user)->post("/applicant/applications/{$application->id}/qualifications", [
            'country_id' => $inst->country_id,
            'awarding_institution_id' => $inst->id,
            'awarding_institution_name' => $inst->name,
            'qualification_type_id' => $type->id,
            'title_of_qualification' => 'Diploma in Testing',
            'qualification_title_source' => 'other',
            'applicant_entered_qualification_title' => 'Diploma in Testing',
            'names_as_on_qualification_document' => str_repeat('A', 256),
            'award_date' => now()->subYear()->toDateString(),
            'certificate_number' => 'CERT-NAMES-004',
        ])->assertSessionHasErrors('names_as_on_qualification_document');
    }

    public function test_existing_qualification_can_be_updated_with_document_names(): void
    {
        $user = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $application = $this->makeApplication($user);
        [$inst] = $this->makeInstitutionPair();
        $type = $this->diplomaQualificationType();
        $title = QualificationTitle::query()->create(['name' => 'Catalog Diploma', 'is_active' => true]);

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_id' => $inst->id,
            'awarding_institution_name' => $inst->name,
            'qualification_holder_name' => 'Holder Name',
            'country_id' => $inst->country_id,
            'nrc_passport_number' => '111111/11/1',
            'certificate_number' => 'CERT-OLD',
            'title_of_qualification' => $title->name,
            'qualification_title_id' => $title->id,
            'qualification_title_source' => 'catalog',
            'award_date' => now()->subYears(2)->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ]);

        $this->actingAs($user)->put("/applicant/applications/{$application->id}/qualification", [
            'qualification_id' => $qualification->id,
            'country_id' => $inst->country_id,
            'awarding_institution_id' => $inst->id,
            'awarding_institution_name' => $inst->name,
            'qualification_type_id' => $type->id,
            'title_of_qualification' => $title->name,
            'qualification_title_id' => $title->id,
            'qualification_title_source' => 'catalog',
            'names_as_on_qualification_document' => 'MARY C. MWANSA',
            'award_date' => now()->subYear()->toDateString(),
            'certificate_number' => 'CERT-UPDATED',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $qualification->refresh();
        $this->assertSame('MARY C. MWANSA', $qualification->names_as_on_qualification_document);
    }

    public function test_applicant_review_page_displays_document_names(): void
    {
        $user = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $application = $this->makeApplication($user);
        [$inst] = $this->makeInstitutionPair();
        $type = $this->diplomaQualificationType();

        Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_id' => $inst->id,
            'awarding_institution_name' => $inst->name,
            'qualification_holder_name' => 'Mary Chanda Mwansa',
            'country_id' => $inst->country_id,
            'nrc_passport_number' => '222222/22/2',
            'certificate_number' => 'CERT-SHOW',
            'title_of_qualification' => 'Bachelor of Arts',
            'names_as_on_qualification_document' => 'MARY C. MWANSA',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ]);

        $this->actingAs($user)
            ->get("/applicant/applications/{$application->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Applicant/Applications/Show')
                ->where('application.qualifications.0.names_as_on_qualification_document', 'MARY C. MWANSA')
            );
    }

    public function test_legacy_qualification_without_document_names_displays_not_captured_on_applicant_show(): void
    {
        $user = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $application = $this->makeApplication($user);
        [$inst] = $this->makeInstitutionPair();
        $type = $this->diplomaQualificationType();

        Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_id' => $inst->id,
            'awarding_institution_name' => $inst->name,
            'qualification_holder_name' => 'Legacy Holder',
            'country_id' => $inst->country_id,
            'nrc_passport_number' => '333333/33/3',
            'certificate_number' => 'CERT-LEGACY',
            'title_of_qualification' => 'Legacy Diploma',
            'names_as_on_qualification_document' => null,
            'award_date' => now()->subYears(3)->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ]);

        $this->actingAs($user)
            ->get("/applicant/applications/{$application->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('application.qualifications.0.names_as_on_qualification_document', null)
            );
    }

    public function test_admin_verification_page_includes_document_names(): void
    {
        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->assignRole('Super Admin');

        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $application = $this->makeApplication($applicant);
        [$inst] = $this->makeInstitutionPair();
        $type = $this->diplomaQualificationType();

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_id' => $inst->id,
            'awarding_institution_name' => $inst->name,
            'qualification_holder_name' => 'Mary Chanda Mwansa',
            'country_id' => $inst->country_id,
            'nrc_passport_number' => '444444/44/4',
            'certificate_number' => 'CERT-ADMIN',
            'title_of_qualification' => 'Bachelor of Arts',
            'names_as_on_qualification_document' => 'MARY C. MWANSA',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ]);

        $this->actingAs($admin)
            ->get("/admin/verification/qualifications/{$qualification->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Verification/Qualifications/Show')
                ->where('qualification.names_as_on_qualification_document', 'MARY C. MWANSA')
            );
    }

    /**
     * @return array<int, AwardingInstitution>
     */
    private function makeInstitutionPair(int $count = 1): array
    {
        $country = Country::query()->firstOrCreate(
            ['iso_code' => 'ZMB'],
            ['name' => 'Zambia', 'is_active' => true, 'sort_order' => 1],
        );

        $institutions = [];
        for ($i = 0; $i < $count; $i++) {
            $institutions[] = AwardingInstitution::query()->create([
                'country_id' => $country->id,
                'name' => 'Institution '.($i + 1),
                'is_active' => true,
                'sort_order' => $i + 1,
            ]);
        }

        return $institutions;
    }

    private function makeApplication(?User $user = null): Application
    {
        $user ??= User::factory()->activated()->create(['applicant_type' => 'individual']);

        return Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-NAMES-'.random_int(1000, 9999),
            'applicant_user_id' => $user->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => 'draft',
            'is_foreign' => false,
            'metadata' => [],
        ]);
    }

    private function diplomaQualificationType(): QualificationType
    {
        return QualificationType::query()
            ->where('zqf_level_code', 'L6')
            ->firstOrFail();
    }
}
