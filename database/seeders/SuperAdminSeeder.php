<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $email = (string) (config('zaqa.super_admin.email') ?: 'superadmin@zaqa.gov.zm');
        $phone = (string) (config('zaqa.super_admin.phone') ?: '260000000000');
        $password = (string) (config('zaqa.super_admin.password') ?: 'ChangeMe@2026');

        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'uuid' => (string) Str::uuid(),
                'name' => (string) (config('zaqa.super_admin.name') ?: 'ZAQA Super Admin'),
                'phone_primary' => $phone,
                'phone_secondary' => null,
                'password' => Hash::make($password),
                'applicant_type' => null,
                'is_active' => true,
                'email_verified_at' => now(),
                'phone_verified_at' => now(),
            ],
        );

        // Keep user active and verified if rerun.
        $user->forceFill([
            'is_active' => true,
            'email_verified_at' => $user->email_verified_at ?: now(),
            'phone_verified_at' => $user->phone_verified_at ?: now(),
        ])->save();

        if (! $user->hasRole('Super Admin')) {
            $user->assignRole('Super Admin');
        }

        // Ensure the Super Admin role is synced with all permissions and the user can access immediately.
        /** @var Role $role */
        $role = Role::query()->firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $role->syncPermissions(Permission::query()->where('guard_name', 'web')->get());
        $user->syncRoles([$role->name]);
    }
}

