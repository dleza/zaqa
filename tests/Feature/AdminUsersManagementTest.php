<?php

namespace Tests\Feature;

use App\Mail\AdminStaffAccountCreatedMail;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminUsersManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_super_admin_can_view_managed_user_dashboard_payload(): void
    {
        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->assignRole('Super Admin');

        $department = Department::query()->create([
            'name' => 'Verification',
            'code' => 'VER',
            'description' => null,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $managedUser = User::factory()->activated()->create([
            'applicant_type' => null,
            'name' => 'Review Officer',
            'first_name' => 'Review',
            'last_name' => 'Officer',
            'email' => 'review.officer@example.test',
            'department_id' => $department->id,
        ]);
        $managedUser->assignRole('Verification Officer Level 2');

        AuditLog::query()->create([
            'event_type' => 'identity.user_logged_in',
            'module' => 'Identity',
            'action_name' => 'user_logged_in',
            'message' => 'User logged in.',
            'actor_user_id' => $managedUser->id,
            'actor_name_snapshot' => $managedUser->name,
            'entity_type' => User::class,
            'entity_id' => $managedUser->id,
            'before_state' => [],
            'after_state' => [],
            'metadata' => [],
        ]);

        $this->actingAs($admin)
            ->get("/admin/users/{$managedUser->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Users/Show')
                ->where('user.id', $managedUser->id)
                ->where('user.email', 'review.officer@example.test')
                ->where('user.primary_role', 'Verification Officer Level 2')
                ->where('can.edit', true)
                ->where('can.resend_login_email', true)
                ->has('recent_activity')
                ->has('stats.cards')
                ->has('access_areas')
            );
    }

    public function test_super_admin_can_open_edit_page(): void
    {
        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->assignRole('Super Admin');

        $managedUser = User::factory()->activated()->create([
            'applicant_type' => null,
            'email' => 'finance.user@example.test',
        ]);
        $managedUser->assignRole('Finance Officer');

        $this->actingAs($admin)
            ->get("/admin/users/{$managedUser->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Users/Edit')
                ->where('user.id', $managedUser->id)
                ->where('user.email', 'finance.user@example.test')
                ->has('roles')
                ->has('departments')
            );
    }

    public function test_super_admin_can_update_managed_user_details_and_role(): void
    {
        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->assignRole('Super Admin');

        $departmentA = Department::query()->create([
            'name' => 'Finance',
            'code' => 'FIN',
            'description' => null,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $departmentB = Department::query()->create([
            'name' => 'Verification',
            'code' => 'VER',
            'description' => null,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $managedUser = User::factory()->activated()->create([
            'applicant_type' => null,
            'name' => 'Finance User',
            'first_name' => 'Finance',
            'last_name' => 'User',
            'email' => 'finance.user@example.test',
            'phone_primary' => '260970000001',
            'department_id' => $departmentA->id,
        ]);
        $managedUser->assignRole('Finance Officer');

        $this->actingAs($admin)
            ->put("/admin/users/{$managedUser->id}", [
                'first_name' => 'Verification',
                'last_name' => 'Officer',
                'email' => 'verification.officer@example.test',
                'phone_primary' => '260970000099',
                'phone_secondary' => '260970000100',
                'department_id' => $departmentB->id,
                'role' => 'Verification Officer Level 1',
            ])
            ->assertRedirect("/admin/users/{$managedUser->id}");

        $managedUser->refresh();

        $this->assertSame('Verification', $managedUser->first_name);
        $this->assertSame('Officer', $managedUser->last_name);
        $this->assertSame('Verification Officer', $managedUser->name);
        $this->assertSame('verification.officer@example.test', $managedUser->email);
        $this->assertSame('260970000099', $managedUser->phone_primary);
        $this->assertSame('260970000100', $managedUser->phone_secondary);
        $this->assertSame($departmentB->id, $managedUser->department_id);
        $this->assertTrue($managedUser->hasRole('Verification Officer Level 1'));
        $this->assertFalse($managedUser->hasRole('Finance Officer'));

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'admin.managed_user_updated',
            'entity_type' => User::class,
            'entity_id' => $managedUser->id,
        ]);
    }

    public function test_super_admin_create_user_queues_login_details_email(): void
    {
        Mail::fake();

        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->assignRole('Super Admin');

        $this->actingAs($admin)
            ->post('/admin/users', [
                'first_name' => 'New',
                'last_name' => 'Officer',
                'email' => 'new.officer@example.test',
                'phone_primary' => '260970000123',
                'role' => 'Finance Officer',
            ])
            ->assertRedirect('/admin/users')
            ->assertSessionHas('generated_password')
            ->assertSessionHas('generated_password_for', 'new.officer@example.test');

        $created = User::query()->where('email', 'new.officer@example.test')->first();
        $this->assertNotNull($created);
        $this->assertTrue($created->hasRole('Finance Officer'));
        $this->assertNotNull($created->email_verified_at);

        Mail::assertQueued(AdminStaffAccountCreatedMail::class, function (AdminStaffAccountCreatedMail $mailable): bool {
            return $mailable->email === 'new.officer@example.test'
                && $mailable->roleName === 'Finance Officer'
                && $mailable->plainTextPassword !== ''
                && str_contains($mailable->loginUrl, '/login');
        });

        $this->assertDatabaseHas('email_logs', [
            'email' => 'new.officer@example.test',
            'template_key' => 'admin_staff_account_created',
            'status' => 'queued',
        ]);
    }

    public function test_view_only_user_cannot_edit_or_update_managed_user(): void
    {
        $viewerRole = Role::query()->firstOrCreate([
            'name' => 'User Viewer',
            'guard_name' => 'web',
        ]);
        $viewerRole->syncPermissions(['dashboard.view', 'admin.users.view']);

        $viewer = User::factory()->activated()->create(['applicant_type' => null]);
        $viewer->assignRole($viewerRole);
        $viewer->givePermissionTo('dashboard.view', 'admin.users.view');

        $managedUser = User::factory()->activated()->create([
            'applicant_type' => null,
            'email' => 'readonly.target@example.test',
        ]);
        $managedUser->assignRole('Auditor');

        $this->actingAs($viewer)
            ->get("/admin/users/{$managedUser->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Users/Show')
                ->where('can.edit', false)
            );

        $this->actingAs($viewer)->get("/admin/users/{$managedUser->id}/edit")->assertForbidden();
        $this->actingAs($viewer)->put("/admin/users/{$managedUser->id}", [
            'first_name' => 'Nope',
            'last_name' => 'Nope',
            'email' => 'nope@example.test',
            'role' => 'Auditor',
        ])->assertForbidden();
    }

    public function test_super_admin_can_resend_login_email_when_user_never_logged_in(): void
    {
        Mail::fake();

        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->assignRole('Super Admin');

        $managedUser = User::factory()->activated()->create([
            'applicant_type' => null,
            'email' => 'never.logged.in@example.test',
            'last_login_at' => null,
        ]);
        $managedUser->assignRole('Finance Officer');

        $oldPasswordHash = $managedUser->password;

        $this->actingAs($admin)
            ->from("/admin/users/{$managedUser->id}")
            ->post("/admin/users/{$managedUser->id}/resend-login-email")
            ->assertRedirect("/admin/users/{$managedUser->id}")
            ->assertSessionHas('success')
            ->assertSessionHas('generated_password')
            ->assertSessionHas('generated_password_for', 'never.logged.in@example.test');

        $managedUser->refresh();
        $this->assertNotSame($oldPasswordHash, $managedUser->password);

        Mail::assertQueued(AdminStaffAccountCreatedMail::class, function (AdminStaffAccountCreatedMail $mailable): bool {
            return $mailable->email === 'never.logged.in@example.test'
                && $mailable->roleName === 'Finance Officer'
                && $mailable->plainTextPassword !== '';
        });

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'admin.managed_user_login_email_resent',
            'entity_type' => User::class,
            'entity_id' => $managedUser->id,
        ]);
    }

    public function test_resend_login_email_blocked_when_user_has_logged_in(): void
    {
        Mail::fake();

        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->assignRole('Super Admin');

        $managedUser = User::factory()->activated()->create([
            'applicant_type' => null,
            'email' => 'already.used@example.test',
            'last_login_at' => now()->subDay(),
        ]);
        $managedUser->assignRole('Auditor');

        $this->actingAs($admin)
            ->get("/admin/users/{$managedUser->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.resend_login_email', false)
            );

        $this->actingAs($admin)
            ->from("/admin/users/{$managedUser->id}")
            ->post("/admin/users/{$managedUser->id}/resend-login-email")
            ->assertRedirect("/admin/users/{$managedUser->id}")
            ->assertSessionHasErrors('resend_login_email');

        Mail::assertNothingQueued();
    }
}
