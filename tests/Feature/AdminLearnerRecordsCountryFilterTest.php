<?php

namespace Tests\Feature;

use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\LearnerRecord;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLearnerRecordsCountryFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_country_filter_limits_records_and_institutions_list(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->assignRole('Super Admin');

        $zmb = Country::query()->create(['iso_code' => 'ZMB', 'name' => 'Zambia', 'is_active' => true, 'sort_order' => 0]);
        $zaf = Country::query()->create(['iso_code' => 'ZAF', 'name' => 'South Africa', 'is_active' => true, 'sort_order' => 0]);

        $unza = AwardingInstitution::query()->create(['country_id' => $zmb->id, 'name' => 'UNZA', 'is_active' => true, 'sort_order' => 0]);
        $uct = AwardingInstitution::query()->create(['country_id' => $zaf->id, 'name' => 'UCT', 'is_active' => true, 'sort_order' => 0]);

        $r1 = LearnerRecord::query()->create([
            'awarding_institution_id' => $unza->id,
            'program_of_study' => 'Computer Science',
            'year_awarded' => 2022,
            'first_name' => 'Alice',
            'last_name' => 'Zed',
            'source_type' => 'import',
        ]);
        $r2 = LearnerRecord::query()->create([
            'awarding_institution_id' => $uct->id,
            'program_of_study' => 'Engineering',
            'year_awarded' => 2023,
            'first_name' => 'Bob',
            'last_name' => 'Y',
            'source_type' => 'import',
        ]);

        $resp = $this->actingAs($admin)->get('/admin/learner-records?country_id='.$zaf->id);
        $resp->assertOk();

        $page = json_decode(json_encode($resp->viewData('page')), true);
        $props = $page['props'] ?? [];

        $recordIds = collect($props['records']['data'] ?? [])->pluck('id')->all();
        $this->assertSame([$r2->id], $recordIds);

        $institutionIds = collect($props['institutions'] ?? [])->pluck('id')->all();
        $this->assertSame([$uct->id], $institutionIds);

        $countries = collect($props['countries'] ?? []);
        $this->assertTrue($countries->contains(fn ($c) => (int) $c['id'] === (int) $zmb->id));
        $this->assertTrue($countries->contains(fn ($c) => (int) $c['id'] === (int) $zaf->id));

        // Sanity: without filter, both records are visible.
        $resp2 = $this->actingAs($admin)->get('/admin/learner-records');
        $resp2->assertOk();
        $page2 = json_decode(json_encode($resp2->viewData('page')), true);
        $recordIds2 = collect(($page2['props']['records']['data'] ?? []))->pluck('id')->sort()->values()->all();
        $this->assertSame(collect([$r1->id, $r2->id])->sort()->values()->all(), $recordIds2);
    }
}

