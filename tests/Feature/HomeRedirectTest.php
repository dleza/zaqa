<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_root_redirects_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_authenticated_staff_root_redirects_to_admin_dashboard(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->assignRole('Super Admin');

        $this->actingAs($user)->get('/')->assertRedirect(route('admin.dashboard'));
    }

    public function test_authenticated_applicant_root_redirects_to_applicant_dashboard(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $user->assignRole('Applicant');

        $this->actingAs($user)->get('/')->assertRedirect(route('applicant.dashboard'));
    }

    public function test_authenticated_staff_login_redirects_to_admin_dashboard_without_loop(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->assignRole('Super Admin');

        $this->actingAs($user)->get('/login')->assertRedirect(route('admin.dashboard'));
    }
}
