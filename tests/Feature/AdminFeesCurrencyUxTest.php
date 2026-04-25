<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminFeesCurrencyUxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BillingCategoriesSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_creating_fee_with_decimal_amount_stores_minor_units(): void
    {
        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->givePermissionTo('settings.fees.create');
        $admin->givePermissionTo('dashboard.view');

        $this->actingAs($admin);

        $categoryId = \App\Models\BillingCategory::query()->firstOrFail()->id;

        $resp = $this->post('/admin/settings/fees', [
            'billing_category_id' => $categoryId,
            'local_fee' => '50.25',
            'foreign_fee' => '1200',
            'currency' => 'ZMW',
            'effective_from' => now()->toDateString(),
            'effective_to' => null,
            'is_active' => true,
            'change_reason' => 'Test',
        ]);

        $resp->assertRedirect('/admin/settings/fees');

        $this->assertDatabaseHas('fee_structures', [
            'billing_category_id' => $categoryId,
            'local_fee_cents' => 5025,
            'foreign_fee_cents' => 120000,
            'currency' => 'ZMW',
            'is_active' => 1,
        ]);
    }

    public function test_malformed_fee_amount_is_rejected(): void
    {
        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->givePermissionTo('settings.fees.create');
        $admin->givePermissionTo('dashboard.view');

        $this->actingAs($admin);

        $categoryId = \App\Models\BillingCategory::query()->firstOrFail()->id;

        $resp = $this->from('/admin/settings/fees/create')->post('/admin/settings/fees', [
            'billing_category_id' => $categoryId,
            'local_fee' => '50.999',
            'foreign_fee' => '1200',
            'currency' => 'ZMW',
            'effective_from' => now()->toDateString(),
            'effective_to' => null,
            'is_active' => true,
        ]);

        $resp->assertRedirect('/admin/settings/fees/create');
        $resp->assertSessionHasErrors(['local_fee']);
    }
}

