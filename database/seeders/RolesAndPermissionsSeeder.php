<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * @var array<int,string>
     */
    private array $permissions = [
        'dashboard.view',

        'admin.users.view',
        'admin.users.create',
        'admin.users.edit',
        'admin.users.disable',

        'admin.applicants.view',

        'admin.roles.view',
        'admin.roles.manage',

        'admin.applications.view',
        'admin.finance.view',

        // Finance (granular)
        'finance.dashboard.view',
        'finance.payment_proofs.view',
        'finance.payment_proofs.review',
        'finance.payment_proofs.approve',
        'finance.payment_proofs.reject',
        'finance.payments.view',
        'finance.payments.detail',
        'finance.payments.correct',
        'finance.receipts.view',
        'finance.reports.view',

        'admin.verification.view',
        'admin.certificates.view',
        'admin.audit.view',
        'admin.reference_data.manage',

        // Learner achievement records
        'learner_records.view',
        'learner_records.import',

        // Institution integration API
        'institution_api.manage',
        'institution_api.logs.view',
        'institution_api.docs.view',

        // Reports
        'reports.sla.view',
        'reports.view',

        // Verification module (granular)
        'verification.pool.view',
        'verification.assign',
        'verification.level1.process',
        'verification.send_back',
        'verification.level2.review',
        'verification.decide.approve',
        'verification.decide.reject',
        'verification.certificate.issue',
        'verification.overdue.view',

        // Settings / reference data modules
        'settings.countries.view',
        'settings.countries.create',
        'settings.countries.edit',
        'settings.countries.delete',

        'settings.certificate_subjects.view',
        'settings.certificate_subjects.create',
        'settings.certificate_subjects.edit',
        'settings.certificate_subjects.delete',

        'settings.awarding_institutions.view',
        'settings.awarding_institutions.create',
        'settings.awarding_institutions.edit',
        'settings.awarding_institutions.delete',

        'settings.qualification_types.view',
        'settings.qualification_types.create',
        'settings.qualification_types.edit',
        'settings.qualification_types.delete',

        'settings.billing_categories.view',
        'settings.billing_categories.create',
        'settings.billing_categories.edit',
        'settings.billing_categories.delete',

        'settings.fees.view',
        'settings.fees.create',
        'settings.fees.edit',
        'settings.fees.delete',

        'settings.departments.view',
        'settings.departments.create',
        'settings.departments.edit',
        'settings.departments.delete',

        'sms.balance.view',
        'sms.balance.manage',
        'sms.logs.view',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->permissions as $name) {
            Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        /** @var Role $superAdmin */
        $superAdmin = Role::query()->firstOrCreate([
            'name' => 'Super Admin',
            'guard_name' => 'web',
        ]);

        // Baseline roles (required by ZAQA)
        Role::query()->firstOrCreate(['name' => 'Applicant', 'guard_name' => 'web']);

        $financeOfficer = Role::query()->firstOrCreate(['name' => 'Finance Officer', 'guard_name' => 'web']);
        $financeOfficer->syncPermissions(Permission::query()->where('guard_name', 'web')->whereIn('name', [
            'dashboard.view',
            'admin.finance.view',
            'admin.applicants.view',
            'finance.dashboard.view',
            'finance.payment_proofs.view',
            'finance.payment_proofs.review',
            'finance.payment_proofs.approve',
            'finance.payment_proofs.reject',
            'finance.payments.view',
            'finance.payments.detail',
            'finance.payments.correct',
            'finance.receipts.view',
            'finance.reports.view',
            'verification.pool.view',
        ])->get());

        $ver1 = Role::query()->firstOrCreate(['name' => 'Verification Officer Level 1', 'guard_name' => 'web']);
        $ver1->syncPermissions(Permission::query()->where('guard_name', 'web')->whereIn('name', [
            'dashboard.view',
            'admin.applications.view',
            'admin.verification.view',
            'verification.pool.view',
            'verification.level1.process',
            'verification.send_back',
        ])->get());

        $ver2 = Role::query()->firstOrCreate(['name' => 'Verification Officer Level 2', 'guard_name' => 'web']);
        $ver2->syncPermissions(Permission::query()->where('guard_name', 'web')->whereIn('name', [
            'dashboard.view',
            'admin.applications.view',
            'admin.verification.view',
            'admin.certificates.view',
            'reports.view',
            'verification.pool.view',
            'verification.assign',
            'verification.send_back',
            'verification.level2.review',
            'verification.decide.approve',
            'verification.decide.reject',
            'verification.certificate.issue',
            'verification.overdue.view',
        ])->get());

        $auditor = Role::query()->firstOrCreate(['name' => 'Auditor', 'guard_name' => 'web']);
        $auditor->syncPermissions(Permission::query()->where('guard_name', 'web')->whereIn('name', [
            'dashboard.view',
            'admin.audit.view',
        ])->get());

        // Super Admin always has all permissions that exist in the system.
        $superAdmin->syncPermissions(Permission::query()->where('guard_name', 'web')->get());
    }
}
