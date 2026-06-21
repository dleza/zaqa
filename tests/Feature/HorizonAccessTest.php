<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HorizonAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_unauthenticated_user_cannot_access_horizon_outside_local(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $response = $this->get('/horizon');

        $this->assertTrue(in_array($response->status(), [403, 302], true));
    }

    public function test_applicant_cannot_access_horizon(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $this->actingAs($applicant)->get('/horizon')->assertForbidden();
    }

    public function test_finance_officer_cannot_access_horizon(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->assignRole('Finance Officer');

        $this->actingAs($user)->get('/horizon')->assertForbidden();
    }

    public function test_level1_officer_cannot_access_horizon(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->assignRole('Verification Officer Level 1');

        $this->actingAs($user)->get('/horizon')->assertForbidden();
    }

    public function test_level2_officer_cannot_access_horizon(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->assignRole('Verification Officer Level 2');

        $this->actingAs($user)->get('/horizon')->assertForbidden();
    }

    public function test_super_admin_can_access_horizon(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->assignRole('Super Admin');

        $this->actingAs($user)->get('/horizon')->assertOk();
    }
}
