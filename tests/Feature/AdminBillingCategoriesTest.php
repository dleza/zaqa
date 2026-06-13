<?php

namespace Tests\Feature;

use App\Models\BillingCategory;
use App\Models\User;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminBillingCategoriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BillingCategoriesSeeder::class);
    }

    public function test_authorized_admin_can_view_billing_categories_index(): void
    {
        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->givePermissionTo(['dashboard.view', 'settings.billing_categories.view']);

        $this->actingAs($admin)
            ->get('/admin/settings/billing-categories')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Settings/BillingCategories/Index')
                ->has('categories.data', 4)
            );
    }

    public function test_admin_can_create_billing_category(): void
    {
        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->givePermissionTo(['dashboard.view', 'settings.billing_categories.create']);

        $this->actingAs($admin)
            ->post('/admin/settings/billing-categories', [
                'name' => 'Professional Qualifications',
                'code' => 'local-professional',
                'description' => 'Professional body awards',
                'local_processing_days' => 21,
                'foreign_processing_days' => 45,
                'is_active' => true,
                'sort_order' => 15,
            ])
            ->assertRedirect('/admin/settings/billing-categories');

        $this->assertDatabaseHas('billing_categories', [
            'name' => 'Professional Qualifications',
            'code' => 'LOCAL_PROFESSIONAL',
            'is_active' => true,
        ]);
    }

    public function test_admin_can_rename_billing_category(): void
    {
        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->givePermissionTo(['dashboard.view', 'settings.billing_categories.edit']);

        $category = BillingCategory::query()->where('code', 'LOCAL_CERTS_DIPLOMAS')->firstOrFail();

        $this->actingAs($admin)
            ->put("/admin/settings/billing-categories/{$category->id}", [
                'name' => 'Local Certificates and Diplomas (Updated)',
                'description' => $category->description,
                'local_processing_days' => $category->local_processing_days,
                'foreign_processing_days' => $category->foreign_processing_days,
                'is_active' => true,
                'sort_order' => $category->sort_order,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('billing_categories', [
            'id' => $category->id,
            'code' => 'LOCAL_CERTS_DIPLOMAS',
            'name' => 'Local Certificates and Diplomas (Updated)',
        ]);
    }

    public function test_system_foreign_category_cannot_be_deactivated(): void
    {
        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->givePermissionTo(['dashboard.view', 'settings.billing_categories.edit']);

        $category = BillingCategory::query()->where('code', BillingCategory::CODE_FOREIGN_QUALIFICATIONS)->firstOrFail();

        $this->actingAs($admin)
            ->put("/admin/settings/billing-categories/{$category->id}", [
                'name' => $category->name,
                'description' => $category->description,
                'local_processing_days' => $category->local_processing_days,
                'foreign_processing_days' => $category->foreign_processing_days,
                'is_active' => false,
                'sort_order' => $category->sort_order,
            ])
            ->assertSessionHasErrors('is_active');

        $this->assertTrue((bool) $category->fresh()->is_active);
    }

    public function test_system_foreign_category_cannot_be_deleted(): void
    {
        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->givePermissionTo(['dashboard.view', 'settings.billing_categories.delete']);

        $category = BillingCategory::query()->where('code', BillingCategory::CODE_FOREIGN_QUALIFICATIONS)->firstOrFail();

        $this->actingAs($admin)
            ->delete("/admin/settings/billing-categories/{$category->id}")
            ->assertStatus(422);

        $this->assertTrue((bool) $category->fresh()->is_active);
    }

    public function test_new_category_appears_in_fee_create_dropdown(): void
    {
        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->givePermissionTo(['dashboard.view', 'settings.fees.create', 'settings.billing_categories.create']);

        BillingCategory::query()->create([
            'name' => 'New Test Category',
            'code' => 'NEW_TEST_CAT',
            'description' => null,
            'local_processing_days' => 14,
            'foreign_processing_days' => 30,
            'is_active' => true,
            'sort_order' => 99,
        ]);

        $this->actingAs($admin)
            ->get('/admin/settings/fees/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Settings/Fees/Create')
                ->where('billing_categories', fn ($categories) => collect($categories)->contains(
                    fn ($c) => ($c['name'] ?? null) === 'New Test Category',
                ))
            );
    }
}
