<?php

namespace Tests\Feature;

use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\InstitutionIntegration;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\UnzaInstitutionIntegrationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnzaInstitutionIntegrationSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_no_ops_when_local_dev_env_vars_are_missing(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $country = Country::query()->firstOrCreate(
            ['iso_code' => 'ZMB'],
            ['name' => 'Zambia', 'is_active' => true, 'sort_order' => 1]
        );

        AwardingInstitution::query()->create([
            'country_id' => $country->id,
            'name' => 'University of Zambia',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        putenv('UNZA_SIS_LOOKUP_URL');
        unset($_ENV['UNZA_SIS_LOOKUP_URL'], $_SERVER['UNZA_SIS_LOOKUP_URL']);

        $this->seed(UnzaInstitutionIntegrationSeeder::class);

        $this->assertSame(0, InstitutionIntegration::query()->count());
    }

    public function test_seeder_creates_integration_when_local_dev_env_vars_are_set(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $country = Country::query()->firstOrCreate(
            ['iso_code' => 'ZMB'],
            ['name' => 'Zambia', 'is_active' => true, 'sort_order' => 1]
        );

        $institution = AwardingInstitution::query()->create([
            'country_id' => $country->id,
            'name' => 'University of Zambia',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        putenv('UNZA_SIS_LOOKUP_URL=http://127.0.0.1:8001/api/zaqa/v1/learner-lookup');
        putenv('UNZA_SIS_LOOKUP_TOKEN=local-dev-token');
        $_ENV['UNZA_SIS_LOOKUP_URL'] = 'http://127.0.0.1:8001/api/zaqa/v1/learner-lookup';
        $_ENV['UNZA_SIS_LOOKUP_TOKEN'] = 'local-dev-token';

        $this->seed(UnzaInstitutionIntegrationSeeder::class);

        $integration = InstitutionIntegration::query()
            ->where('awarding_institution_id', $institution->id)
            ->first();

        $this->assertNotNull($integration);
        $this->assertTrue((bool) $integration->supports_pull);
        $this->assertSame('http://127.0.0.1:8001/api/zaqa/v1/learner-lookup', $integration->lookup_url);
        $this->assertSame('bearer_token', $integration->auth_type);
        $this->assertSame('local-dev-token', $integration->credentials['bearer_token'] ?? null);

        putenv('UNZA_SIS_LOOKUP_URL');
        putenv('UNZA_SIS_LOOKUP_TOKEN');
        unset($_ENV['UNZA_SIS_LOOKUP_URL'], $_ENV['UNZA_SIS_LOOKUP_TOKEN']);
    }
}
