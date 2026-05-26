<?php

namespace Tests\Feature;

use App\Domain\Verification\QualificationAutoAssignmentService;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\Qualification;
use App\Models\User;
use App\Models\VerificationAssignmentCategory;
use App\Models\VerificationAssignmentCategoryUser;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssignmentCategoryMultiMappingTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->assignRole('Super Admin');
        return $admin;
    }

    private function makeLevel1(string $name = 'Officer'): User
    {
        $u = User::factory()->activated()->create(['applicant_type' => null, 'name' => $name]);
        $u->assignRole('Verification Officer Level 1');
        return $u;
    }

    private function makeApplication(User $applicant): Application
    {
        return Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-AA-'.rand(1000, 9999),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => 'submitted',
            'verification_state' => VerificationState::AwaitingAutoVerification,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
            'service_deadline_at' => now()->addDays(14),
            'paid_at' => now(),
        ]);
    }

    public function test_backfill_migrates_legacy_single_value_mappings_into_pivots(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $zmb = Country::query()->create(['iso_code' => 'ZMB', 'name' => 'Zambia', 'is_active' => true, 'sort_order' => 0]);
        $usa = Country::query()->create(['iso_code' => 'USA', 'name' => 'United States', 'is_active' => true, 'sort_order' => 0]);
        $inst = AwardingInstitution::query()->create(['country_id' => $zmb->id, 'name' => 'Legacy Uni', 'is_active' => true, 'sort_order' => 0]);

        $legacyForeign = VerificationAssignmentCategory::query()->create([
            'name' => 'Legacy USA',
            'type' => 'foreign_country',
            'country_id' => $usa->id,
            'is_active' => true,
        ]);
        $legacyLocal = VerificationAssignmentCategory::query()->create([
            'name' => 'Legacy Local',
            'type' => 'local_institution',
            'awarding_institution_id' => $inst->id,
            'is_active' => true,
        ]);

        DB::table('verification_assignment_category_countries')->where('verification_assignment_category_id', $legacyForeign->id)->delete();
        DB::table('verification_assignment_category_awarding_institutions')->where('verification_assignment_category_id', $legacyLocal->id)->delete();

        $migration = include base_path('database/migrations/2026_05_26_120005_backfill_verification_assignment_category_mappings.php');
        ($migration)::backfill();

        $this->assertDatabaseHas('verification_assignment_category_countries', [
            'verification_assignment_category_id' => $legacyForeign->id,
            'country_id' => $usa->id,
        ]);
        $this->assertDatabaseHas('verification_assignment_category_awarding_institutions', [
            'verification_assignment_category_id' => $legacyLocal->id,
            'awarding_institution_id' => $inst->id,
        ]);
    }

    public function test_admin_validation_requires_mappings_and_prevents_overlaps_for_active_categories(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = $this->makeAdmin();

        $a = Country::query()->create(['iso_code' => 'ZAF', 'name' => 'South Africa', 'is_active' => true, 'sort_order' => 0]);
        $b = Country::query()->create(['iso_code' => 'BWA', 'name' => 'Botswana', 'is_active' => true, 'sort_order' => 0]);

        // Requires at least one country.
        $this->actingAs($admin)->post('/admin/verification/assignment-categories', [
            'type' => 'foreign_country',
            'name' => 'Southern Africa (Foreign)',
            'is_active' => true,
            'countries' => [],
        ])->assertSessionHasErrors(['countries']);

        // Foreign cannot submit institutions.
        $this->actingAs($admin)->post('/admin/verification/assignment-categories', [
            'type' => 'foreign_country',
            'name' => 'Invalid Payload (Foreign)',
            'is_active' => true,
            'countries' => [(int) $a->id],
            'awarding_institutions' => [999],
        ])->assertSessionHasErrors(['awarding_institutions']);

        // Create active category with multiple countries.
        $this->actingAs($admin)->post('/admin/verification/assignment-categories', [
            'type' => 'foreign_country',
            'name' => 'Southern Africa (Foreign)',
            'is_active' => true,
            'countries' => [(int) $a->id, (int) $b->id],
        ])->assertRedirect();

        $category = VerificationAssignmentCategory::query()->where('name', 'Southern Africa (Foreign)')->firstOrFail();
        $this->assertDatabaseHas('verification_assignment_category_countries', [
            'verification_assignment_category_id' => $category->id,
            'country_id' => $a->id,
        ]);

        // Overlap blocked for active.
        $this->actingAs($admin)->post('/admin/verification/assignment-categories', [
            'type' => 'foreign_country',
            'name' => 'Overlap (Foreign)',
            'is_active' => true,
            'countries' => [(int) $b->id],
        ])->assertSessionHasErrors(['countries']);

        // Inactive can overlap.
        $this->actingAs($admin)->post('/admin/verification/assignment-categories', [
            'type' => 'foreign_country',
            'name' => 'Overlap (Foreign)',
            'is_active' => false,
            'countries' => [(int) $b->id],
        ])->assertRedirect();

        $overlap = VerificationAssignmentCategory::query()->where('name', 'Overlap (Foreign)')->firstOrFail();
        $this->actingAs($admin)->post("/admin/verification/assignment-categories/{$overlap->id}/reactivate")
            ->assertSessionHasErrors(['is_active']);
    }

    public function test_local_categories_require_institutions_and_disallow_overlaps_for_active_categories(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = $this->makeAdmin();

        $zmb = Country::query()->create(['iso_code' => 'ZMB', 'name' => 'Zambia', 'is_active' => true, 'sort_order' => 0]);
        $unza = AwardingInstitution::query()->create(['country_id' => $zmb->id, 'name' => 'UNZA', 'is_active' => true, 'sort_order' => 0]);
        $cbu = AwardingInstitution::query()->create(['country_id' => $zmb->id, 'name' => 'CBU', 'is_active' => true, 'sort_order' => 0]);

        $this->actingAs($admin)->post('/admin/verification/assignment-categories', [
            'type' => 'local_institution',
            'name' => 'Public Universities',
            'is_active' => true,
            'awarding_institutions' => [],
        ])->assertSessionHasErrors(['awarding_institutions']);

        // Local cannot submit countries.
        $this->actingAs($admin)->post('/admin/verification/assignment-categories', [
            'type' => 'local_institution',
            'name' => 'Invalid Payload (Local)',
            'is_active' => true,
            'awarding_institutions' => [(int) $unza->id],
            'countries' => [999],
        ])->assertSessionHasErrors(['countries']);

        $this->actingAs($admin)->post('/admin/verification/assignment-categories', [
            'type' => 'local_institution',
            'name' => 'Public Universities',
            'is_active' => true,
            'awarding_institutions' => [(int) $unza->id, (int) $cbu->id],
        ])->assertRedirect();

        // Overlap blocked for active.
        $this->actingAs($admin)->post('/admin/verification/assignment-categories', [
            'type' => 'local_institution',
            'name' => 'Overlap (Local)',
            'is_active' => true,
            'awarding_institutions' => [(int) $cbu->id],
        ])->assertSessionHasErrors(['awarding_institutions']);

        // Inactive can overlap, but cannot be activated.
        $this->actingAs($admin)->post('/admin/verification/assignment-categories', [
            'type' => 'local_institution',
            'name' => 'Overlap (Local)',
            'is_active' => false,
            'awarding_institutions' => [(int) $cbu->id],
        ])->assertRedirect();

        $overlap = VerificationAssignmentCategory::query()->where('name', 'Overlap (Local)')->firstOrFail();
        $this->actingAs($admin)->post("/admin/verification/assignment-categories/{$overlap->id}/reactivate")
            ->assertSessionHasErrors(['is_active']);
    }

    public function test_updating_category_ignores_its_own_mappings_but_blocks_conflicting_active_mappings(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = $this->makeAdmin();

        $a = Country::query()->create(['iso_code' => 'AGO', 'name' => 'Angola', 'is_active' => true, 'sort_order' => 0]);
        $b = Country::query()->create(['iso_code' => 'NAM', 'name' => 'Namibia', 'is_active' => true, 'sort_order' => 0]);
        $c = Country::query()->create(['iso_code' => 'ZWE', 'name' => 'Zimbabwe', 'is_active' => true, 'sort_order' => 0]);

        $this->actingAs($admin)->post('/admin/verification/assignment-categories', [
            'type' => 'foreign_country',
            'name' => 'Pool A',
            'is_active' => true,
            'countries' => [(int) $a->id, (int) $b->id],
        ])->assertRedirect();
        $poolA = VerificationAssignmentCategory::query()->where('name', 'Pool A')->firstOrFail();

        $this->actingAs($admin)->post('/admin/verification/assignment-categories', [
            'type' => 'foreign_country',
            'name' => 'Pool C',
            'is_active' => true,
            'countries' => [(int) $c->id],
        ])->assertRedirect();

        // Same mappings should pass (ignore self).
        $this->actingAs($admin)->put("/admin/verification/assignment-categories/{$poolA->id}", [
            'name' => 'Pool A (renamed)',
            'is_active' => true,
            'countries' => [(int) $a->id, (int) $b->id],
        ])->assertRedirect();

        // Adding a conflicting mapping should fail.
        $this->actingAs($admin)->put("/admin/verification/assignment-categories/{$poolA->id}", [
            'name' => 'Pool A (conflict)',
            'is_active' => true,
            'countries' => [(int) $a->id, (int) $c->id],
        ])->assertSessionHasErrors(['countries']);
    }

    public function test_auto_assignment_routes_using_pivot_mappings_and_fails_safe_on_ambiguous_mapping(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $usa = Country::query()->create(['iso_code' => 'USA', 'name' => 'United States', 'is_active' => true, 'sort_order' => 0]);
        $can = Country::query()->create(['iso_code' => 'CAN', 'name' => 'Canada', 'is_active' => true, 'sort_order' => 0]);

        $category = VerificationAssignmentCategory::query()->create([
            'name' => 'North America',
            'type' => 'foreign_country',
            'is_active' => true,
        ]);
        $category->countries()->sync([(int) $usa->id, (int) $can->id]);

        $level1 = $this->makeLevel1('Officer NA');
        VerificationAssignmentCategoryUser::query()->create([
            'verification_assignment_category_id' => $category->id,
            'user_id' => $level1->id,
            'is_active' => true,
            'is_available' => true,
        ]);

        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $application = $this->makeApplication($applicant);

        $q1 = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'X',
            'qualification_holder_name' => 'Jane Doe',
            'country_id' => $usa->id,
            'nrc_passport_number' => '222222/22/2',
            'title_of_qualification' => 'Diploma',
            'award_date' => '2024-01-10',
            'qualification_type' => 'L6',
            'is_foreign_qualification' => true,
            'verification_state' => VerificationState::AwaitingAssignment,
            'transcript_required' => false,
        ]);
        $q2 = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'X',
            'qualification_holder_name' => 'Jane Doe',
            'country_id' => $can->id,
            'nrc_passport_number' => '333333/33/3',
            'title_of_qualification' => 'Diploma',
            'award_date' => '2024-01-10',
            'qualification_type' => 'L6',
            'is_foreign_qualification' => true,
            'verification_state' => VerificationState::AwaitingAssignment,
            'transcript_required' => false,
        ]);

        app(QualificationAutoAssignmentService::class)->autoAssign($q1);
        app(QualificationAutoAssignmentService::class)->autoAssign($q2);
        $q1->refresh();
        $q2->refresh();
        $this->assertSame($level1->id, (int) $q1->assigned_verifier_id);
        $this->assertSame($level1->id, (int) $q2->assigned_verifier_id);

        // Corrupt data: map same country to another active category. Runtime must fail safe.
        $bad = VerificationAssignmentCategory::query()->create([
            'name' => 'Bad Duplicate',
            'type' => 'foreign_country',
            'is_active' => true,
        ]);
        $bad->countries()->sync([(int) $usa->id]);

        $q3 = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'X',
            'qualification_holder_name' => 'Jane Doe',
            'country_id' => $usa->id,
            'nrc_passport_number' => '444444/44/4',
            'title_of_qualification' => 'Diploma',
            'award_date' => '2024-01-10',
            'qualification_type' => 'L6',
            'is_foreign_qualification' => true,
            'verification_state' => VerificationState::AwaitingAssignment,
            'transcript_required' => false,
        ]);

        $res = app(QualificationAutoAssignmentService::class)->autoAssign($q3);
        $this->assertFalse($res->assigned);
        $q3->refresh();
        $this->assertNull($q3->assigned_verifier_id);
        $this->assertNotNull($q3->assignment_failure_reason);
        $this->assertStringContainsString('Ambiguous', (string) $q3->assignment_failure_reason);
    }

    public function test_assignment_categories_pages_expose_expected_props_for_multi_mapping_ui(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = $this->makeAdmin();

        $country = Country::query()->create(['iso_code' => 'USA', 'name' => 'United States', 'is_active' => true, 'sort_order' => 0]);
        $category = VerificationAssignmentCategory::query()->create(['name' => 'North America', 'type' => 'foreign_country', 'is_active' => true]);
        $category->countries()->attach((int) $country->id);

        $create = $this->actingAs($admin)->get('/admin/verification/assignment-categories/create');
        $create->assertOk();
        $createProps = json_decode(json_encode($create->viewData('page')), true)['props'];
        $this->assertIsArray($createProps['countries'] ?? null);
        $this->assertIsArray($createProps['institutions'] ?? null);

        $index = $this->actingAs($admin)->get('/admin/verification/assignment-categories');
        $index->assertOk();
        $indexProps = json_decode(json_encode($index->viewData('page')), true)['props'];
        $first = $indexProps['categories']['data'][0] ?? [];
        $this->assertArrayHasKey('mapped_count', $first);
        $this->assertArrayHasKey('mapped_sample', $first);

        $show = $this->actingAs($admin)->get("/admin/verification/assignment-categories/{$category->id}");
        $show->assertOk();
        $showProps = json_decode(json_encode($show->viewData('page')), true)['props'];
        $this->assertIsArray(($showProps['category']['countries'] ?? null));

        $edit = $this->actingAs($admin)->get("/admin/verification/assignment-categories/{$category->id}/edit");
        $edit->assertOk();
        $editProps = json_decode(json_encode($edit->viewData('page')), true)['props'];
        $this->assertIsArray(($editProps['category']['country_ids'] ?? null));
    }
}
