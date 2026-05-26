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

        $users = [
            [
                'email' => 'verifier.l2.one@zaqa.gov.zm',
                'name' => 'Demo Verification Officer L2',
                'phone_primary' => '260977000012',
                'role' => 'Verification Officer Level 2',
            ],
            [
                'email' => 'verifier.l2.two@zaqa.gov.zm',
                'name' => 'Demo Verification Officer L2',
                'phone_primary' => '260977000022',
                'role' => 'Verification Officer Level 2',
            ],
            [
                'email' => 'verifier.l1@zaqa.gov.zm',
                'name' => 'Demo Verification Officer L1',
                'phone_primary' => '260977000001',
                'role' => 'Verification Officer Level 1',
            ],
            [
                'email' => 'verifier.l1.two@zaqa.gov.zm',
                'name' => 'Demo Verification Officer L1',
                'phone_primary' => '260977000002',
                'role' => 'Verification Officer Level 1',
            ],
            [
                'email' => 'verifier.l1.foreign@zaqa.gov.zm',
                'name' => 'Demo Verification Officer L1',
                'phone_primary' => '260977000003',
                'role' => 'Verification Officer Level 1',
            ],
            [
                'email' => 'finance@zaqa.gov.zm',
                'name' => 'Demo Finance Officer',
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

