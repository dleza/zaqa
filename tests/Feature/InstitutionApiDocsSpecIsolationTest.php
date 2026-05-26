<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstitutionApiDocsSpecIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_institution_openapi_spec_only_contains_institution_paths(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('Super Admin');

        $res = $this->actingAs($admin)->get('/docs/institution-api/openapi.yaml');
        $res->assertOk();

        $body = (string) $res->getContent();
        $this->assertStringContainsString('/api/institution/v1/learner-records:', $body);
        $this->assertStringNotContainsString('/admin/', $body);
        $this->assertStringNotContainsString('/applicant/', $body);
    }
}

