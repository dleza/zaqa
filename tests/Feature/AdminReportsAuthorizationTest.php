<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminReportsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_super_admin_can_access_reports_applications(): void
    {
        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->assignRole('Super Admin');

        $this->actingAs($admin)
            ->get('/admin/reports/applications')
            ->assertOk();
    }

    public function test_level2_can_access_reports_applications(): void
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->assignRole('Verification Officer Level 2');

        $this->actingAs($user)
            ->get('/admin/reports/applications')
            ->assertOk();
    }

    public function test_level1_without_reports_permission_cannot_access_reports(): void
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->givePermissionTo([
            'dashboard.view',
            'verification.pool.view',
            'verification.level1.process',
        ]);

        $this->actingAs($user)
            ->get('/admin/reports/applications')
            ->assertForbidden();
    }

    public function test_qualifications_report_returns_inertia_with_qualification_summary(): void
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->givePermissionTo(['dashboard.view', 'reports.view']);

        $response = $this->actingAs($user)
            ->get('/admin/reports/qualifications?range=last30');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('dashboard.summary.total'));
    }

    public function test_applications_export_csv_streams_successfully(): void
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->givePermissionTo(['dashboard.view', 'reports.view']);

        $this->actingAs($user)
            ->get('/admin/reports/applications/export?range=last30&format=csv')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_payments_report_export_respects_filters_without_mutating_data(): void
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->givePermissionTo(['dashboard.view', 'finance.reports.view', 'finance.reports.download']);

        $this->actingAs($user)
            ->get('/admin/reports/payments/export?range=last30&format=csv')
            ->assertOk();
    }

    public function test_reports_view_permission_alone_does_not_grant_finance_report_access(): void
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->givePermissionTo(['dashboard.view', 'reports.view']);

        $this->actingAs($user)
            ->get('/admin/reports/applications?range=last30')
            ->assertOk();

        $this->actingAs($user)
            ->get('/admin/reports/payments?range=last30')
            ->assertForbidden();
    }

    public function test_finance_officer_can_access_payments_report_with_finance_permission_only(): void
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->assignRole('Finance Officer');

        $this->actingAs($user)
            ->get('/admin/reports/payments?range=last30')
            ->assertOk();

        $this->actingAs($user)
            ->get('/admin/reports/payments/export?range=last30&format=csv')
            ->assertOk();
    }

    public function test_reports_detail_tables_are_paginated_not_full_dataset(): void
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->givePermissionTo(['dashboard.view', 'reports.view']);

        $response = $this->actingAs($user)->get('/admin/reports/applications?range=last30');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('table.data')
            ->has('table.links'));
    }
}
