<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminProfileDashboardTest extends TestCase
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

    public function test_admin_can_view_profile_dashboard_payload(): void
    {
        $dept = Department::query()->create([
            'name' => 'Verification',
            'code' => 'VER',
            'description' => null,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $admin = User::factory()->activated()->create([
            'applicant_type' => null,
            'name' => 'ZAQA Super Admin',
            'email' => 'superadmin@example.test',
            'department_id' => $dept->id,
        ]);
        $admin->assignRole('Super Admin');

        AuditLog::query()->create([
            'event_type' => 'admin.profile_updated',
            'module' => 'Account',
            'action_name' => 'profile_updated',
            'message' => 'Admin updated their profile details.',
            'actor_user_id' => $admin->id,
            'entity_type' => User::class,
            'entity_id' => $admin->id,
            'before_state' => [],
            'after_state' => [],
            'metadata' => [],
        ]);

        $res = $this->actingAs($admin)->get('/admin/profile');
        $res->assertOk();
        $res->assertInertia(fn ($page) => $page->component('Admin/Profile/Show', shouldExist: false));

        $props = $this->inertiaProps($res);

        $this->assertSame($admin->id, (int) $props['profile']['id']);
        $this->assertSame('ZAQA Super Admin', (string) $props['profile']['name']);
        $this->assertSame('superadmin@example.test', (string) $props['profile']['email']);
        $this->assertArrayHasKey('profile_photo_url', $props['profile']);
        $this->assertNotEmpty($props['departments']);
        $this->assertLessThanOrEqual(10, count($props['recent_activity']));
        $this->assertIsArray($props['stats']['cards'] ?? null);
    }

    public function test_profile_photo_can_be_uploaded_and_removed(): void
    {
        Storage::fake('public');

        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->assignRole('Super Admin');

        $file = UploadedFile::fake()->image('avatar.jpg', 300, 300);

        $this->actingAs($admin)
            ->post('/admin/profile/photo', ['photo' => $file])
            ->assertRedirect('/admin/profile');

        $admin->refresh();
        $this->assertNotNull($admin->profile_photo_path);
        Storage::disk('public')->assertExists($admin->profile_photo_path);

        $this->actingAs($admin)
            ->delete('/admin/profile/photo')
            ->assertRedirect('/admin/profile');

        $admin->refresh();
        $this->assertNull($admin->profile_photo_path);
    }

    public function test_invalid_profile_photo_is_rejected(): void
    {
        Storage::fake('public');

        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->assignRole('Super Admin');

        $file = UploadedFile::fake()->create('vector.svg', 10, 'image/svg+xml');

        $this->actingAs($admin)
            ->post('/admin/profile/photo', ['photo' => $file])
            ->assertSessionHasErrors(['photo']);
    }

    public function test_profile_update_does_not_allow_role_or_status_changes(): void
    {
        $deptA = Department::query()->create(['name' => 'Dept A', 'code' => 'A', 'description' => null, 'is_active' => true, 'sort_order' => 1]);
        $deptB = Department::query()->create(['name' => 'Dept B', 'code' => 'B', 'description' => null, 'is_active' => true, 'sort_order' => 2]);

        $admin = User::factory()->activated()->create([
            'applicant_type' => null,
            'department_id' => $deptA->id,
            'phone_primary' => '260900000001',
        ]);
        $admin->assignRole('Super Admin');

        $this->actingAs($admin)
            ->put('/admin/profile', [
                'phone_primary' => '260900000002',
                'phone_secondary' => '260900000003',
                'department_id' => $deptB->id,
                // These must be ignored by backend validation.
                'is_active' => false,
                'roles' => ['Verification Officer Level 1'],
                'email' => 'hacked@example.test',
            ])
            ->assertRedirect('/admin/profile');

        $admin->refresh();
        $this->assertSame('260900000002', (string) $admin->phone_primary);
        $this->assertSame('260900000003', (string) $admin->phone_secondary);
        $this->assertSame($deptB->id, (int) $admin->department_id);
        $this->assertTrue((bool) $admin->is_active);
        $this->assertNotSame('hacked@example.test', (string) $admin->email);
        $this->assertTrue($admin->hasRole('Super Admin'));
    }
}

