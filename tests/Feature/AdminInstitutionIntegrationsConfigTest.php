<?php

namespace Tests\Feature;

use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\InstitutionIntegration;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminInstitutionIntegrationsConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_save_pull_integration_settings(): void
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
            'name' => 'Config University',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->actingAs($admin)->post("/admin/integrations/institution-integrations/{$institution->id}", [
            'is_active' => true,
            'supports_push' => true,
            'supports_pull' => true,
            'lookup_url' => 'https://institution.test/lookup',
            'auth_type' => 'bearer_token',
            'bearer_token' => 'secret-token',
            'request_method' => 'POST',
            'timeout_seconds' => 15,
            'retry_attempts' => 2,
            'rate_limit_per_minute' => 60,
            'driver' => 'generic_rest',
        ])->assertRedirect();

        $integration = InstitutionIntegration::query()->where('awarding_institution_id', $institution->id)->firstOrFail();
        $this->assertTrue((bool) $integration->supports_pull);
        $this->assertSame('https://institution.test/lookup', $integration->lookup_url);
        $this->assertSame('bearer_token', $integration->auth_type);
        $this->assertIsArray($integration->credentials);
        $this->assertSame('secret-token', $integration->credentials['bearer_token'] ?? null);
    }

    public function test_unza_style_pull_integration_can_be_configured_via_admin_without_env(): void
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
            'name' => 'University of Zambia',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->actingAs($admin)->post("/admin/integrations/institution-integrations/{$institution->id}", [
            'is_active' => true,
            'supports_push' => false,
            'supports_pull' => true,
            'lookup_url' => 'https://sis.unza.ac.zm/api/zaqa/v1/learner-lookup',
            'auth_type' => 'bearer_token',
            'bearer_token' => 'production-shared-token',
            'request_method' => 'POST',
            'timeout_seconds' => 15,
            'retry_attempts' => 2,
            'rate_limit_per_minute' => 60,
            'driver' => 'generic_rest',
        ])->assertRedirect();

        $integration = InstitutionIntegration::query()
            ->where('awarding_institution_id', $institution->id)
            ->firstOrFail();

        $this->assertTrue((bool) $integration->is_active);
        $this->assertTrue((bool) $integration->supports_pull);
        $this->assertFalse((bool) $integration->supports_push);
        $this->assertSame('generic_rest', $integration->driver);
        $this->assertSame('POST', $integration->request_method);
        $this->assertSame('bearer_token', $integration->auth_type);
        $this->assertSame('https://sis.unza.ac.zm/api/zaqa/v1/learner-lookup', $integration->lookup_url);
        $this->assertSame('production-shared-token', $integration->credentials['bearer_token'] ?? null);
    }
}

