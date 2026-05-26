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
}

