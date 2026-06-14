<?php

namespace Tests\Feature;

use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\InstitutionIntegration;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminInstitutionIntegrationsTestConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_integration_uses_post_for_connection_test(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->assignRole('Super Admin');

        $country = Country::query()->firstOrCreate(
            ['iso_code' => 'ZMB'],
            ['name' => 'Zambia', 'is_active' => true, 'sort_order' => 0]
        );

        $institution = AwardingInstitution::query()->create([
            'country_id' => (int) $country->id,
            'name' => 'POST Test University',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        InstitutionIntegration::query()->create([
            'awarding_institution_id' => (int) $institution->id,
            'is_active' => true,
            'supports_push' => false,
            'supports_pull' => true,
            'lookup_url' => 'https://institution.test/api/zaqa/v1/learner-lookup',
            'auth_type' => 'bearer_token',
            'credentials' => ['bearer_token' => 'secret-token'],
            'request_method' => 'POST',
            'timeout_seconds' => 15,
            'retry_attempts' => 0,
            'driver' => 'generic_rest',
        ]);

        Http::fake([
            'https://institution.test/api/zaqa/v1/learner-lookup' => Http::response([
                'found' => false,
                'source_reference' => null,
                'confidence_hint' => null,
                'record' => null,
            ], 200),
        ]);

        $this->actingAs($admin)
            ->post("/admin/integrations/institution-integrations/{$institution->id}/test")
            ->assertRedirect()
            ->assertSessionHas('success');

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && ($request->data()['student_id'] ?? null) === '0000000'
                && $request->hasHeader('Authorization', 'Bearer secret-token');
        });
    }
}
