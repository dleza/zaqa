<?php

namespace Database\Seeders;

use App\Enums\ApplicationStatus;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

class DemoVerificationFlowSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Demo password for all seeded staff accounts (change anytime).
        $password = 'ChangeMe@2026';

        // Email pattern: demo.{role}.{variant}@zaqa.gov.zm — role is visible at a glance.
        // | Email                         | Role        | Purpose                          |
        // |-------------------------------|-------------|----------------------------------|
        // | demo.l2.1@zaqa.gov.zm         | Level 2     | Assign & oversee verification    |
        // | demo.l2.2@zaqa.gov.zm         | Level 2     | Second L2 for workload demos     |
        // | demo.l1.1@zaqa.gov.zm         | Level 1     | Local institution verification   |
        // | demo.l1.2@zaqa.gov.zm         | Level 1     | Second L1 for assignment demos   |
        // | demo.l1.foreign@zaqa.gov.zm   | Level 1     | Foreign qualification verification |
        // | demo.finance@zaqa.gov.zm      | Finance     | Payment proof review             |
        $users = [
            [
                'email' => 'demo.l2.1@zaqa.gov.zm',
                'name' => 'Demo L2 — Officer 1',
                'phone_primary' => '260977000012',
                'role' => 'Verification Officer Level 2',
            ],
            [
                'email' => 'demo.l2.2@zaqa.gov.zm',
                'name' => 'Demo L2 — Officer 2',
                'phone_primary' => '260977000022',
                'role' => 'Verification Officer Level 2',
            ],
            [
                'email' => 'demo.l1.1@zaqa.gov.zm',
                'name' => 'Demo L1 — Officer 1',
                'phone_primary' => '260977000001',
                'role' => 'Verification Officer Level 1',
            ],
            [
                'email' => 'demo.l1.2@zaqa.gov.zm',
                'name' => 'Demo L1 — Officer 2',
                'phone_primary' => '260977000002',
                'role' => 'Verification Officer Level 1',
            ],
            [
                'email' => 'demo.l1.foreign@zaqa.gov.zm',
                'name' => 'Demo L1 — Foreign',
                'phone_primary' => '260977000003',
                'role' => 'Verification Officer Level 1',
            ],
            [
                'email' => 'demo.finance@zaqa.gov.zm',
                'name' => 'Demo Finance',
                'phone_primary' => '260977000033',
                'role' => 'Finance Officer',
            ],
        ];

        foreach ($users as $row) {
            /** @var User $user */
            $user = User::query()->updateOrCreate(
                ['email' => $row['email']],
                [
                    'uuid' => (string) Str::uuid(),
                    'name' => $row['name'],
                    'phone_primary' => $row['phone_primary'],
                    'phone_secondary' => null,
                    'password' => Hash::make($password),
                    'applicant_type' => null,
                    'is_active' => true,
                    'email_verified_at' => now(),
                    'phone_verified_at' => now(),
                ],
            );

            $user->forceFill([
                'is_active' => true,
                'email_verified_at' => $user->email_verified_at ?: now(),
                'phone_verified_at' => $user->phone_verified_at ?: now(),
            ])->save();

            if (! $user->hasRole($row['role'])) {
                $user->syncRoles([$row['role']]);
            }
        }

        // Put a chosen application into the correct first verification state (so action buttons appear).
        // By default we use application ID 9 (matches your example URL).
        /** @var Application|null $app */
        $app = Application::query()->find(9);
        if (! $app) {
            return;
        }

        $app->forceFill([
            'current_status' => ApplicationStatus::Submitted,
            'verification_state' => VerificationState::AwaitingAssignment,
            'assigned_level1_user_id' => null,
            'assigned_by_level2_user_id' => null,
        ])->save();
    }
}

