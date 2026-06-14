<?php

namespace Tests\Feature;

use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\InstitutionApiClient;
use App\Models\InstitutionIntegration;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InstitutionApiClientPullIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function makeClientWithInstitution(): array
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

        $client = InstitutionApiClient::query()->create([
            'awarding_institution_id' => (int) $institution->id,
            'name' => 'UNZA Integration Client',
            'contact_name' => 'IT Team',
            'contact_email' => 'it@unza.test',
            'is_active' => true,
            'scopes' => ['learner-records:write'],
            'created_by_user_id' => $admin->id,
        ]);

        return [$admin, $client, $institution];
    }

    public function test_client_show_includes_pull_integration_summary(): void
    {
        [$admin, $client, $institution] = $this->makeClientWithInstitution();

        InstitutionIntegration::query()->create([
            'awarding_institution_id' => (int) $institution->id,
            'is_active' => true,
            'supports_push' => true,
            'supports_pull' => true,
            'lookup_url' => 'https://sis.test/api/zaqa/v1/learner-lookup',
            'auth_type' => 'bearer_token',
            'credentials' => ['bearer_token' => 'existing-token'],
            'request_method' => 'POST',
            'timeout_seconds' => 15,
            'retry_attempts' => 2,
            'driver' => 'generic_rest',
        ]);

        $response = $this->actingAs($admin)
            ->get("/admin/integrations/institution-api-clients/{$client->id}");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Integrations/InstitutionApiClients/Show')
            ->has('pull_integration')
            ->where('pull_integration.supports_pull', true)
            ->where('pull_integration.lookup_url', 'https://sis.test/api/zaqa/v1/learner-lookup')
            ->where('pull_integration.has_credentials', true)
            ->has('pull_integration_urls.save')
            ->has('pull_integration_urls.generate_token')
        );
    }

    public function test_admin_can_save_pull_integration_from_client_page_without_env(): void
    {
        [$admin, $client] = $this->makeClientWithInstitution();

        $this->actingAs($admin)->post("/admin/integrations/institution-api-clients/{$client->id}/pull-integration", [
            'is_active' => true,
            'supports_pull' => true,
            'lookup_url' => 'https://sis.test/api/zaqa/v1/learner-lookup',
            'auth_type' => 'bearer_token',
            'bearer_token' => 'shared-secret-token',
            'request_method' => 'POST',
            'timeout_seconds' => 15,
            'retry_attempts' => 2,
            'driver' => 'generic_rest',
        ])->assertRedirect();

        $integration = InstitutionIntegration::query()
            ->where('awarding_institution_id', $client->awarding_institution_id)
            ->firstOrFail();

        $this->assertTrue((bool) $integration->supports_pull);
        $this->assertSame('https://sis.test/api/zaqa/v1/learner-lookup', $integration->lookup_url);
        $this->assertSame('shared-secret-token', $integration->credentials['bearer_token'] ?? null);
    }

    public function test_admin_can_generate_pull_lookup_token_from_client_page(): void
    {
        [$admin, $client, $institution] = $this->makeClientWithInstitution();

        $response = $this->actingAs($admin)
            ->post("/admin/integrations/institution-api-clients/{$client->id}/pull-integration/generate-token");

        $response->assertRedirect();
        $response->assertSessionHas('institution_pull_lookup_plaintext_token');

        $plain = session('institution_pull_lookup_plaintext_token');
        $this->assertIsString($plain);
        $this->assertGreaterThan(32, strlen($plain));

        $integration = InstitutionIntegration::query()
            ->where('awarding_institution_id', $institution->id)
            ->firstOrFail();

        $this->assertSame('bearer_token', $integration->auth_type);
        $this->assertTrue((bool) $integration->supports_pull);
        $this->assertSame($plain, $integration->credentials['bearer_token'] ?? null);
    }

    public function test_admin_can_test_pull_connection_from_client_page(): void
    {
        [$admin, $client, $institution] = $this->makeClientWithInstitution();

        InstitutionIntegration::query()->create([
            'awarding_institution_id' => (int) $institution->id,
            'is_active' => true,
            'supports_push' => true,
            'supports_pull' => true,
            'lookup_url' => 'https://sis.test/api/zaqa/v1/learner-lookup',
            'auth_type' => 'bearer_token',
            'credentials' => ['bearer_token' => 'shared-secret-token'],
            'request_method' => 'POST',
            'timeout_seconds' => 15,
            'retry_attempts' => 2,
            'driver' => 'generic_rest',
        ]);

        Http::fake([
            'https://sis.test/api/zaqa/v1/learner-lookup' => Http::response([
                'found' => false,
                'source_reference' => null,
                'confidence_hint' => null,
                'record' => null,
            ], 200),
        ]);

        $this->actingAs($admin)
            ->post("/admin/integrations/institution-api-clients/{$client->id}/pull-integration/test")
            ->assertRedirect()
            ->assertSessionHas('success');
    }
}
