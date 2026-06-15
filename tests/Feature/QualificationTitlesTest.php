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
use Database\Seeders\QualificationTypesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class QualificationTitlesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BillingCategoriesSeeder::class);
        $this->seed(QualificationTypesSeeder::class);
    }

    private function settingsAdmin(array $permissions = []): User
    {
        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->givePermissionTo(array_merge([
            'dashboard.view',
            'settings.qualification_titles.view',
            'settings.qualification_titles.create',
            'settings.qualification_titles.edit',
            'settings.qualification_titles.delete',
        ], $permissions));

        return $admin;
    }

    public function test_authorized_user_can_view_qualification_titles_index(): void
    {
        QualificationTitle::query()->create(['name' => 'Diploma in Testing', 'is_active' => true]);

        $this->actingAs($this->settingsAdmin())
            ->get('/admin/settings/qualification-titles')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Settings/QualificationTitles/Index'));
    }

    public function test_unauthorized_user_denied_qualification_titles_index(): void
    {
        $user = User::factory()->activated()->create();

        $this->actingAs($user)
            ->get('/admin/settings/qualification-titles')
            ->assertForbidden();
    }

    public function test_admin_can_create_qualification_title(): void
    {
        $admin = $this->settingsAdmin();

        $this->actingAs($admin)->post('/admin/settings/qualification-titles', [
            'name' => 'Bachelor of Science',
            'is_active' => true,
            'sort_order' => 5,
        ])->assertRedirect('/admin/settings/qualification-titles');

        $this->assertDatabaseHas('qualification_titles', [
            'name' => 'Bachelor of Science',
            'name_normalized' => 'bachelor of science',
            'is_active' => 1,
            'sort_order' => 5,
        ]);
    }

    public function test_duplicate_normalized_title_rejected_on_create(): void
    {
        QualificationTitle::query()->create(['name' => 'Diploma in Testing', 'is_active' => true]);

        $this->actingAs($this->settingsAdmin())->post('/admin/settings/qualification-titles', [
            'name' => 'diploma in testing',
            'is_active' => true,
        ])->assertSessionHasErrors('name');
    }

    public function test_admin_can_edit_qualification_title(): void
    {
        $title = QualificationTitle::query()->create(['name' => 'Old Title', 'is_active' => true]);

        $this->actingAs($this->settingsAdmin())->put("/admin/settings/qualification-titles/{$title->id}", [
            'name' => 'Updated Title',
            'is_active' => false,
            'sort_order' => 2,
        ])->assertRedirect();

        $title->refresh();
        $this->assertSame('Updated Title', $title->name);
        $this->assertFalse($title->is_active);
    }

    public function test_admin_can_deactivate_qualification_title_in_use(): void
    {
        $title = QualificationTitle::query()->create(['name' => 'In Use Title', 'is_active' => true]);
        $application = $this->makeApplication();
        Qualification::query()->create($this->qualificationAttributes($application, $title));

        $this->actingAs($this->settingsAdmin())
            ->delete("/admin/settings/qualification-titles/{$title->id}")
            ->assertRedirect();

        $title->refresh();
        $this->assertFalse($title->is_active);
        $this->assertDatabaseHas('qualification_titles', ['id' => $title->id]);
    }

    public function test_import_creates_and_updates_titles_without_duplicates(): void
    {
        Storage::fake('local');
        QualificationTitle::query()->create(['name' => 'Existing Title', 'is_active' => false, 'sort_order' => 0]);

        $csv = "title,qualification_type,is_active,sort_order,description\n";
        $csv .= "Existing Title,,1,10,Updated description\n";
        $csv .= "Brand New Title,,1,0,\n";
        $path = storage_path('app/testing-qualification-titles.csv');
        file_put_contents($path, $csv);
        $file = new UploadedFile($path, 'titles.csv', 'text/csv', null, true);

        $this->actingAs($this->settingsAdmin())->post('/admin/settings/qualification-titles/import', [
            'file' => $file,
        ])->assertRedirect();

        $this->assertDatabaseHas('qualification_titles', [
            'name' => 'Existing Title',
            'is_active' => 1,
            'sort_order' => 10,
        ]);
        $this->assertDatabaseHas('qualification_titles', ['name' => 'Brand New Title']);
        $this->assertSame(2, QualificationTitle::query()->count());
    }

    public function test_applicant_endpoint_returns_active_titles_only(): void
    {
        $user = User::factory()->activated()->create(['applicant_type' => 'individual']);
        [$inst] = $this->makeInstitutionPair();

        QualificationTitle::query()->create(['name' => 'Active Title', 'is_active' => true]);
        QualificationTitle::query()->create(['name' => 'Inactive Title', 'is_active' => false]);

        $res = $this->actingAs($user)->getJson("/applicant/reference/qualification-titles?awarding_institution_id={$inst->id}");
        $res->assertOk();
        $titles = collect($res->json('data'))->pluck('title')->all();
        $this->assertContains('Active Title', $titles);
        $this->assertNotContains('Inactive Title', $titles);
    }

    public function test_institution_with_linked_titles_returns_linked_active_titles_only(): void
    {
        $user = User::factory()->activated()->create(['applicant_type' => 'individual']);
        [$instA, $instB] = $this->makeInstitutionPair(2);

        $linked = QualificationTitle::query()->create(['name' => 'Linked Title', 'is_active' => true]);
        $global = QualificationTitle::query()->create(['name' => 'Global Title', 'is_active' => true]);
        $linked->awardingInstitutions()->sync([$instA->id]);

        $res = $this->actingAs($user)->getJson("/applicant/reference/qualification-titles?awarding_institution_id={$instA->id}");
        $titles = collect($res->json('data'))->pluck('title')->all();
        $this->assertSame(['Linked Title'], $titles);

        $resB = $this->actingAs($user)->getJson("/applicant/reference/qualification-titles?awarding_institution_id={$instB->id}");
        $titlesB = collect($resB->json('data'))->pluck('title')->sort()->values()->all();
        $this->assertEqualsCanonicalizing(['Global Title', 'Linked Title'], $titlesB);
    }

    public function test_institution_without_links_falls_back_to_all_active_titles(): void
    {
        $user = User::factory()->activated()->create(['applicant_type' => 'individual']);
        [$inst] = $this->makeInstitutionPair();

        QualificationTitle::query()->create(['name' => 'Title A', 'is_active' => true]);
        QualificationTitle::query()->create(['name' => 'Title B', 'is_active' => true]);

        $res = $this->actingAs($user)->getJson("/applicant/reference/qualification-titles?awarding_institution_id={$inst->id}");
        $this->assertCount(2, $res->json('data'));
    }

    public function test_catalog_selection_stores_qualification_title_id_and_snapshot(): void
    {
        Storage::fake('local');
        $user = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $application = $this->makeApplication($user);
        [$inst] = $this->makeInstitutionPair();
        $type = $this->diplomaQualificationType();

        $title = QualificationTitle::query()->create(['name' => 'Catalog Title', 'is_active' => true]);

        $response = $this->actingAs($user)->post("/applicant/applications/{$application->id}/qualifications", [
            'country_id' => $inst->country_id,
            'awarding_institution_id' => $inst->id,
            'awarding_institution_name' => $inst->name,
            'qualification_type_id' => $type->id,
            'title_of_qualification' => $title->name,
            'qualification_title_id' => $title->id,
            'qualification_title_source' => 'catalog',
            'award_date' => now()->subYear()->toDateString(),
            'certificate_number' => 'CERT-001',
        ]);
        $response->assertRedirect()->assertSessionHasNoErrors();

        $qualification = Qualification::query()->where('application_id', $application->id)->firstOrFail();
        $response->assertSessionHas('created_qualification_id', $qualification->id);
        $this->assertSame($title->id, $qualification->qualification_title_id);
        $this->assertSame('Catalog Title', $qualification->title_of_qualification);
        $this->assertSame('catalog', $qualification->qualification_title_source?->value);
    }

    public function test_inactive_title_rejected_on_save(): void
    {
        $user = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $application = $this->makeApplication($user);
        [$inst] = $this->makeInstitutionPair();
        $type = $this->diplomaQualificationType();

        $title = QualificationTitle::query()->create(['name' => 'Inactive Catalog', 'is_active' => false]);

        $this->actingAs($user)->post("/applicant/applications/{$application->id}/qualifications", [
            'country_id' => $inst->country_id,
            'awarding_institution_id' => $inst->id,
            'awarding_institution_name' => $inst->name,
            'qualification_type_id' => $type->id,
            'title_of_qualification' => $title->name,
            'qualification_title_id' => $title->id,
            'qualification_title_source' => 'catalog',
            'award_date' => now()->subYear()->toDateString(),
            'certificate_number' => 'CERT-002',
        ])->assertSessionHasErrors('qualification_title_id');
    }

    public function test_unlinked_title_rejected_when_institution_has_linked_titles(): void
    {
        $user = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $application = $this->makeApplication($user);
        [$inst] = $this->makeInstitutionPair();
        $type = $this->diplomaQualificationType();

        $linked = QualificationTitle::query()->create(['name' => 'Allowed', 'is_active' => true]);
        $blocked = QualificationTitle::query()->create(['name' => 'Blocked', 'is_active' => true]);
        $linked->awardingInstitutions()->sync([$inst->id]);

        $this->actingAs($user)->post("/applicant/applications/{$application->id}/qualifications", [
            'country_id' => $inst->country_id,
            'awarding_institution_id' => $inst->id,
            'awarding_institution_name' => $inst->name,
            'qualification_type_id' => $type->id,
            'title_of_qualification' => $blocked->name,
            'qualification_title_id' => $blocked->id,
            'qualification_title_source' => 'catalog',
            'award_date' => now()->subYear()->toDateString(),
            'certificate_number' => 'CERT-003',
        ])->assertSessionHasErrors('qualification_title_id');
    }

    public function test_other_path_still_works_without_title_id(): void
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
            'title_of_qualification' => 'Custom Manual Title',
            'qualification_title_source' => 'other',
            'applicant_entered_qualification_title' => 'Custom Manual Title',
            'award_date' => now()->subYear()->toDateString(),
            'certificate_number' => 'CERT-004',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $qualification = Qualification::query()->where('application_id', $application->id)->firstOrFail();
        $this->assertNull($qualification->qualification_title_id);
        $this->assertSame('other', $qualification->qualification_title_source?->value);
        $this->assertSame('Custom Manual Title', $qualification->title_of_qualification);
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
            'application_number' => 'ZAQA-TITLE-'.random_int(1000, 9999),
            'applicant_user_id' => $user->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => 'draft',
            'is_foreign' => false,
            'metadata' => [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function qualificationAttributes(Application $application, QualificationTitle $title): array
    {
        $type = $this->diplomaQualificationType();

        return [
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test Institution',
            'qualification_holder_name' => 'Holder',
            'nrc_passport_number' => '999999/99/9',
            'title_of_qualification' => $title->name,
            'qualification_title_id' => $title->id,
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ];
    }

    private function diplomaQualificationType(): QualificationType
    {
        return QualificationType::query()
            ->where('zqf_level_code', 'L6')
            ->firstOrFail();
    }
}
