<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardSmsAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function inertiaProps($response): array
    {
        $page = $response->viewData('page');

        return json_decode(json_encode($page), true)['props'];
    }

    public function test_super_admin_sees_low_balance_warning(): void
    {
        \App\Models\SmsBalanceAccount::query()->whereKey(1)->update([
            'balance' => 80,
            'low_balance_threshold' => 100,
            'critical_balance_threshold' => 10,
        ]);

        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->assignRole('Super Admin');

        $response = $this->actingAs($admin)->get('/admin/dashboard');
        $props = $this->inertiaProps($response);

        $this->assertNotEmpty($props['alerts']);
        $this->assertSame('sms_balance_low', $props['alerts'][0]['key']);
        $this->assertContains('sms_balance', collect($props['kpis'])->pluck('key')->all());
    }

    public function test_super_admin_sees_critical_balance_warning(): void
    {
        \App\Models\SmsBalanceAccount::query()->whereKey(1)->update([
            'balance' => 5,
            'low_balance_threshold' => 100,
            'critical_balance_threshold' => 10,
        ]);

        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->assignRole('Super Admin');

        $props = $this->inertiaProps($this->actingAs($admin)->get('/admin/dashboard'));

        $this->assertSame('sms_balance_critical', $props['alerts'][0]['key']);
    }

    public function test_finance_officer_does_not_see_sms_widgets(): void
    {
        $finance = User::factory()->activated()->create(['applicant_type' => null]);
        $finance->assignRole('Finance Officer');

        $props = $this->inertiaProps($this->actingAs($finance)->get('/admin/dashboard'));

        $this->assertSame([], $props['alerts']);
        $this->assertNotContains('sms_balance', collect($props['kpis'])->pluck('key')->all());
    }
}
