<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Reports\ReportAuthorization;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminReportsNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_finance_officer_can_access_reports_index_and_finance_reports_only(): void
    {
        $finance = User::factory()->activated()->create(['applicant_type' => null]);
        $finance->assignRole('Finance Officer');

        $this->actingAs($finance)
            ->get('/admin/reports')
            ->assertRedirect('/admin/reports/payments');

        $this->actingAs($finance)
            ->get('/admin/reports/payments?range=last30')
            ->assertOk();

        $this->actingAs($finance)
            ->get('/admin/reports/payments/export?range=last30&format=csv')
            ->assertOk();
    }

    public function test_finance_officer_cannot_access_verification_reports(): void
    {
        $finance = User::factory()->activated()->create(['applicant_type' => null]);
        $finance->assignRole('Finance Officer');

        $this->actingAs($finance)
            ->get('/admin/reports/applications?range=last30')
            ->assertForbidden();

        $this->actingAs($finance)
            ->get('/admin/reports/qualifications?range=last30')
            ->assertForbidden();

        $this->actingAs($finance)
            ->get('/admin/reports/sla?range=last30')
            ->assertForbidden();
    }

    public function test_super_admin_can_access_reports_index_with_multiple_categories(): void
    {
        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->assignRole('Super Admin');

        $this->actingAs($admin)
            ->get('/admin/reports')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Reports/Index')
                ->has('categories', 5)
            );
    }

    public function test_level_one_cannot_access_verification_reports_but_can_open_my_performance(): void
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->assignRole('Verification Officer Level 1');

        $this->actingAs($user)
            ->get('/admin/reports/applications?range=last30')
            ->assertForbidden();

        $this->actingAs($user)
            ->get('/admin/reports/my-performance')
            ->assertOk();

        $this->actingAs($user)
            ->get('/admin/reports')
            ->assertRedirect('/admin/reports/my-performance');
    }

    public function test_level_two_can_access_verification_and_sla_reports(): void
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->assignRole('Verification Officer Level 2');

        $this->actingAs($user)
            ->get('/admin/reports/applications?range=last30')
            ->assertOk();

        $this->actingAs($user)
            ->get('/admin/reports/sla?range=last30')
            ->assertOk();

        $this->actingAs($user)
            ->get('/admin/reports/payments?range=last30')
            ->assertForbidden();
    }

    public function test_user_without_any_report_permission_cannot_access_reports_index(): void
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->givePermissionTo(['dashboard.view']);

        $this->actingAs($user)
            ->get('/admin/reports')
            ->assertForbidden();
    }

    public function test_report_authorization_index_categories_respect_finance_only_access(): void
    {
        $finance = User::factory()->activated()->create(['applicant_type' => null]);
        $finance->assignRole('Finance Officer');

        $categories = ReportAuthorization::indexCategories($finance);

        $this->assertCount(1, $categories);
        $this->assertSame('finance', $categories[0]['key']);
        $this->assertSame('Finance reports', $categories[0]['label']);
    }
}
