<?php

namespace Tests\Feature;

use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\InstitutionIntegration;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminAwardingInstitutionPullLookupPreviewTest extends TestCase
{
    use RefreshDatabase;

    private const LOOKUP_URL = 'https://sis.test/api/zaqa/v1/learner-lookup';

    public function test_institution_show_includes_pull_lookup_preview_props_when_configured(): void
    {
        $admin = $this->makeAdmin();
        $institution = $this->makeInstitutionWithPullIntegration();

        $this->actingAs($admin)
            ->get("/admin/settings/awarding-institutions/{$institution->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Settings/AwardingInstitutions/Show')
                ->has('pull_lookup')
                ->where('pull_lookup.configured', true)
                ->where('pull_lookup.enabled', true)
                ->where('pull_lookup.lookup_url', self::LOOKUP_URL)
                ->has('pull_lookup.preview_url')
            );
    }

    public function test_admin_can_run_pull_lookup_preview_and_see_record(): void
    {
        $admin = $this->makeAdmin();
        $institution = $this->makeInstitutionWithPullIntegration();

        Http::fake([
            self::LOOKUP_URL => Http::response([
                'found' => true,
                'source_reference' => 'verification_records:42',
                'confidence_hint' => 88,
                'record' => [
                    'student_id' => '2021551041',
                    'certificate_no' => 'UNZA-2026-UG-000237',
                    'first_name' => 'Evelyn',
                    'last_name' => 'Zulu',
                    'other_names' => 'Kefasi',
                    'nrc_number' => '123456/78/9',
                    'passport_no' => null,
                    'program_of_study' => 'Diploma in Nursing',
                    'year_awarded' => 2026,
                    'award_date' => '2026-05-19',
                ],
            ], 200),
        ]);

        $response = $this->actingAs($admin)->postJson(
            "/admin/settings/awarding-institutions/{$institution->id}/pull-lookup-preview",
            [
                'student_id' => '2021551041',
                'program_of_study' => 'Diploma in Nursing',
                'year_awarded' => 2026,
            ],
        );

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('found', true)
            ->assertJsonPath('status', 'found')
            ->assertJsonPath('record.student_id', '2021551041')
            ->assertJsonPath('record.program_of_study', 'Diploma in Nursing')
            ->assertJsonPath('record.nrc_number', '123456/78/9');

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $request->url() === self::LOOKUP_URL
                && $data['student_id'] === '2021551041'
                && $data['program_of_study'] === 'Diploma in Nursing'
                && $data['year_awarded'] === 2026
                && $request->hasHeader('Authorization');
        });
    }

    public function test_preview_requires_at_least_one_identifier(): void
    {
        $admin = $this->makeAdmin();
        $institution = $this->makeInstitutionWithPullIntegration();

        $this->actingAs($admin)
            ->postJson("/admin/settings/awarding-institutions/{$institution->id}/pull-lookup-preview", [
                'first_name' => 'Evelyn',
                'last_name' => 'Zulu',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['student_id']);
    }

    public function test_preview_is_forbidden_without_manage_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $viewer = User::factory()->activated()->create(['applicant_type' => null]);
        $viewer->givePermissionTo('settings.awarding_institutions.view');

        $institution = $this->makeInstitutionWithPullIntegration();

        $this->actingAs($viewer)
            ->postJson("/admin/settings/awarding-institutions/{$institution->id}/pull-lookup-preview", [
                'student_id' => '2021551041',
            ])
            ->assertForbidden();
    }

    private function makeAdmin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->assignRole('Super Admin');

        return $admin;
    }

    private function makeInstitutionWithPullIntegration(): AwardingInstitution
    {
        $country = Country::query()->firstOrCreate(
            ['iso_code' => 'ZMB'],
            ['name' => 'Zambia', 'is_active' => true, 'sort_order' => 0],
        );

        $institution = AwardingInstitution::query()->create([
            'country_id' => (int) $country->id,
            'name' => 'University of Zambia',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        InstitutionIntegration::query()->create([
            'awarding_institution_id' => (int) $institution->id,
            'is_active' => true,
            'supports_push' => false,
            'supports_pull' => true,
            'lookup_url' => self::LOOKUP_URL,
            'auth_type' => 'bearer_token',
            'credentials' => ['bearer_token' => 'preview-token'],
            'request_method' => 'POST',
            'timeout_seconds' => 15,
            'retry_attempts' => 2,
            'driver' => 'generic_rest',
        ]);

        return $institution;
    }
}
