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
        'finance.receipts.view',
        'finance.reports.view',
        'admin.verification.view',
        'admin.certificates.view',
        'admin.audit.view',
        'admin.reference_data.manage',

        // Reports
        'reports.sla.view',

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

        'settings.fees.view',
        'settings.fees.create',
        'settings.fees.edit',
        'settings.fees.delete',

        'settings.departments.view',
        'settings.departments.create',
        'settings.departments.edit',
        'settings.departments.delete',
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
            'finance.dashboard.view',
            'finance.payment_proofs.view',
            'finance.payment_proofs.review',
            'finance.payment_proofs.approve',
            'finance.payment_proofs.reject',
            'finance.payments.view',
            'finance.payments.detail',
            'finance.receipts.view',
            'finance.reports.view',
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
            'reports.sla.view',
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

__halt_compiler();

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
        'admin.verification.view',
        'admin.certificates.view',
        'admin.settings.manage',
        'admin.audit.view',
        'admin.reference_data.manage',

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

        'settings.fees.view',
        'settings.fees.create',
        'settings.fees.edit',
        'settings.fees.delete',

        'settings.departments.view',
        'settings.departments.create',
        'settings.departments.edit',
        'settings.departments.delete',
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
        ])->get());

        $ver1 = Role::query()->firstOrCreate(['name' => 'Verification Officer Level 1', 'guard_name' => 'web']);
        $ver1->syncPermissions(Permission::query()->where('guard_name', 'web')->whereIn('name', [
            'dashboard.view',
            'admin.applications.view',
            'admin.verification.view',
        ])->get());

        $ver2 = Role::query()->firstOrCreate(['name' => 'Verification Officer Level 2', 'guard_name' => 'web']);
        $ver2->syncPermissions(Permission::query()->where('guard_name', 'web')->whereIn('name', [
            'dashboard.view',
            'admin.applications.view',
            'admin.verification.view',
        ])->get());

        // Super Admin always has all permissions that exist in the system.
        $superAdmin->syncPermissions(Permission::query()->where('guard_name', 'web')->get());
    }
}

__halt_compiler();

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
        'admin.verification.view',
        'admin.certificates.view',
        'admin.settings.manage',
        'admin.audit.view',
        'admin.reference_data.manage',

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

        'settings.fees.view',
        'settings.fees.create',
        'settings.fees.edit',
        'settings.fees.delete',

        'settings.departments.view',
        'settings.departments.create',
        'settings.departments.edit',
        'settings.departments.delete',
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
        ])->get());

        $ver1 = Role::query()->firstOrCreate(['name' => 'Verification Officer Level 1', 'guard_name' => 'web']);
        $ver1->syncPermissions(Permission::query()->where('guard_name', 'web')->whereIn('name', [
            'dashboard.view',
            'admin.applications.view',
            'admin.verification.view',
        ])->get());

        $ver2 = Role::query()->firstOrCreate(['name' => 'Verification Officer Level 2', 'guard_name' => 'web']);
        $ver2->syncPermissions(Permission::query()->where('guard_name', 'web')->whereIn('name', [
            'dashboard.view',
            'admin.applications.view',
            'admin.verification.view',
        ])->get());

        // Super Admin always has all permissions that exist in the system.
        $superAdmin->syncPermissions(Permission::query()->where('guard_name', 'web')->get());
    }
}

/*
|-----------------------------------------------------------------------
| Duplicate content removed
|-----------------------------------------------------------------------
| A previous edit duplicated this file content. Everything below is
| intentionally commented out to keep seeding functional.


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
        'admin.verification.view',
        'admin.certificates.view',
        'admin.settings.manage',
        'admin.audit.view',
        'admin.reference_data.manage',

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

        'settings.fees.view',
        'settings.fees.create',
        'settings.fees.edit',
        'settings.fees.delete',

        'settings.departments.view',
        'settings.departments.create',
        'settings.departments.edit',
        'settings.departments.delete',
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
        ])->get());

        $ver1 = Role::query()->firstOrCreate(['name' => 'Verification Officer Level 1', 'guard_name' => 'web']);
        $ver1->syncPermissions(Permission::query()->where('guard_name', 'web')->whereIn('name', [
            'dashboard.view',
            'admin.applications.view',
            'admin.verification.view',
        ])->get());

        $ver2 = Role::query()->firstOrCreate(['name' => 'Verification Officer Level 2', 'guard_name' => 'web']);
        $ver2->syncPermissions(Permission::query()->where('guard_name', 'web')->whereIn('name', [
            'dashboard.view',
            'admin.applications.view',
            'admin.verification.view',
        ])->get());

        // Super Admin always has all permissions that exist in the system.
        $superAdmin->syncPermissions(Permission::query()->where('guard_name', 'web')->get());
    }
}

/*
|-----------------------------------------------------------------------
| Duplicate content removed
|-----------------------------------------------------------------------
| A previous edit duplicated this file content. Everything below is
| intentionally commented out to keep seeding functional.
*/

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
        'admin.verification.view',
        'admin.certificates.view',
        'admin.settings.manage',
        'admin.audit.view',
        'admin.reference_data.manage',

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

        'settings.fees.view',
        'settings.fees.create',
        'settings.fees.edit',
        'settings.fees.delete',

        'settings.departments.view',
        'settings.departments.create',
        'settings.departments.edit',
        'settings.departments.delete',
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
        ])->get());

        $ver1 = Role::query()->firstOrCreate(['name' => 'Verification Officer Level 1', 'guard_name' => 'web']);
        Analyze AGENTS.md, all referenced markdown files, the current admin layout/sidebar, role/permission system, and the current admin routes/pages.

        IMPORTANT CHANGE
        Refactor the **System Settings** admin menu into a proper settings/reference-data section with real submenus and fully implemented pages.
        
        We do NOT want placeholders.
        
        We want real, production-ready CRUD-style management screens for:
        
        1. Countries
        2. Awarding Institutions
        3. Qualification Types
        4. Fees
        5. Departments
        
        These pages must be:
        - permission-based
        - fully implemented
        - clean and premium
        - consistent with the admin layout
        
        GOAL
        Turn “System Settings” from a single flat admin item into a structured admin settings module with submenus and working management pages.
        
        ======================================================================
        MENU / NAVIGATION REQUIREMENTS
        ======================================================================
        
        Update the admin sidebar so **System Settings** becomes a collapsible parent menu with these child items:
        
        System Settings
        - Countries
        - Awarding Institutions
        - Qualification Types
        - Fees
        - Departments
        
        REQUIREMENTS
        1. The parent “System Settings” should expand/collapse cleanly
        2. Child items should have icons where appropriate
        3. Active states should work correctly for:
           - parent menu
           - active child item
        4. Permission-based visibility:
           - only show submenu items the current admin can access
           - only show the parent if at least one child is authorized
        5. Use the same premium sidebar interaction quality already established for admin/applicant layouts
        
        ======================================================================
        ROLE / PERMISSION REQUIREMENTS
        ======================================================================
        
        All actions must be role-based and permission-based.
        
        Create or verify clean permissions such as:
        
        Countries
        - settings.countries.view
        - settings.countries.create
        - settings.countries.edit
        - settings.countries.delete
        
        Awarding Institutions
        - settings.awarding_institutions.view
        - settings.awarding_institutions.create
        - settings.awarding_institutions.edit
        - settings.awarding_institutions.delete
        
        Qualification Types
        - settings.qualification_types.view
        - settings.qualification_types.create
        - settings.qualification_types.edit
        - settings.qualification_types.delete
        
        Fees
        - settings.fees.view
        - settings.fees.create
        - settings.fees.edit
        - settings.fees.delete
        
        Departments
        - settings.departments.view
        - settings.departments.create
        - settings.departments.edit
        - settings.departments.delete
        
        Also:
        - only render menu items the user is allowed to see
        - protect routes with middleware/policies/permission checks
        - protect page actions and buttons the same way
        - do not rely only on hidden UI; backend must enforce access
        
        ======================================================================
        IMPLEMENTATION REQUIREMENTS — REAL PAGES, NO PLACEHOLDERS
        ======================================================================
        
        Implement real admin pages, not placeholder shells.
        
        Each module must have working pages/screens for:
        - list/index page
        - create form
        - edit form
        - delete action with safe confirmation
        - search/filter where appropriate
        - proper empty states
        - permission-aware buttons/actions
        
        If a module should not support hard delete because of referential integrity, implement the correct alternative:
        - soft delete
        - deactivate/disable
        - block delete with clear explanation
        
        Do not create fake “coming soon” pages.
        
        ======================================================================
        1. COUNTRIES MANAGEMENT
        ======================================================================
        
        Implement a full Countries management module.
        
        Expected capabilities:
        - list countries
        - create country
        - edit country
        - delete/deactivate country depending on integrity rules
        - search by country name/code
        - manage active/inactive state
        - optional sort order management
        
        Suggested fields:
        - name
        - iso2 code
        - iso3 code
        - is_active
        - sort_order
        
        UX:
        - clean table/list
        - create/edit form should be compact and centered if it is a form page
        - actions should be clear and premium
        
        Validation:
        - unique codes where applicable
        - required name
        - enforce clean formatting
        
        ======================================================================
        2. AWARDING INSTITUTIONS MANAGEMENT
        ======================================================================
        
        Implement a full Awarding Institutions module.
        
        Expected capabilities:
        - list awarding institutions
        - create awarding institution
        - edit awarding institution
        - delete/deactivate awarding institution depending on integrity rules
        - search/filter by institution name
        - filter by country
        - manage active/inactive state
        
        Suggested fields:
        - country_id
        - name
        - is_active
        
        Requirements:
        - institution must link to a country
        - create/edit form should allow selecting country
        - list page should show country
        - search should be useful and clean
        - do not auto-create “Other” entries from applicant flow unless admins intentionally create them here
        
        ======================================================================
        3. QUALIFICATION TYPES MANAGEMENT
        ======================================================================
        
        Implement a full Qualification Types module.
        
        Expected capabilities:
        - list qualification types
        - create qualification type
        - edit qualification type
        - deactivate/delete where appropriate
        - manage mapping to billing category/fee-driving category
        
        Suggested fields based on the current model direction:
        - zqf level / level code
        - name
        - short name nullable
        - description nullable
        - billing_category_id
        - is_active
        - sort_order
        
        Requirements:
        - clean table/list
        - form with validation
        - ability to assign the correct billing category
        - support for later fee resolution
        
        ======================================================================
        4. FEES MANAGEMENT
        ======================================================================
        
        Implement a full Fees module based on the fee structure/versioning design.
        
        This is important and must be real, not superficial.
        
        Expected capabilities:
        - list fee structures
        - create new fee structure version
        - edit where allowed by business rules
        - activate/deactivate or retire fee versions cleanly
        - view effective dates
        - support local fee and foreign fee
        - support category-based configuration
        - preserve history
        
        Important business rule:
        - old fee structures must remain historically intact
        - invoices already generated must not be affected by later fee changes
        - new fees should be introduced via new effective-dated structure/version rather than mutating historical meaning carelessly
        
        Suggested fields:
        - billing_category_id
        - local_fee_amount
        - foreign_fee_amount
        - currency
        - effective_from
        - effective_to nullable
        - is_active
        - change_reason nullable
        
        UX:
        - clear list/table
        - effective status badge
        - create/edit/version screens
        - clearly indicate current active fee vs historical fee entries
        
        If business rules suggest “create new version” instead of editing active fees directly, implement that cleanly.
        
        ======================================================================
        5. DEPARTMENTS MANAGEMENT
        ======================================================================
        
        Implement a full Departments module.
        
        Expected capabilities:
        - list departments
        - create department
        - edit department
        - deactivate/delete depending on usage
        - search/filter
        - active/inactive state
        
        Suggested fields:
        - name
        - code nullable
        - description nullable
        - is_active
        
        This will be used when assigning staff to departments.
        
        ======================================================================
        UI / UX REQUIREMENTS
        ======================================================================
        
        All settings pages should feel premium and consistent with the admin portal.
        
        LIST PAGES
        - clean header
        - page title + short helper text
        - action button such as “Add country”, “Add institution”, etc.
        - searchable/filterable table
        - empty state
        - status badges
        - permission-based action buttons
        - pagination if needed
        
        FORM PAGES
        - centered form layout for compact forms
        - premium card styling
        - strong labels
        - proper validation messages
        - clear save/cancel actions
        - mobile responsive
        
        DELETE / DEACTIVATE UX
        - confirm destructive actions
        - if delete is blocked because records are in use, show a clear message
        - prefer safe domain behavior over unsafe delete logic
        
        ======================================================================
        TECHNICAL IMPLEMENTATION REQUIREMENTS
        ======================================================================
        
        Generate actual code changes for:
        - admin menu config
        - permission seeding/updating
        - routes
        - controllers/actions
        - form requests
        - policies
        - models/relationships
        - pages/components
        - tests
        
        Use the current stack:
        - Laravel
        - Inertia.js
        - Vue 3
        - TypeScript
        - Tailwind CSS
        - shadcn-vue
        - spatie/laravel-permission
        
        Keep architecture clean:
        - thin controllers
        - validation in Form Requests
        - authorization in policies / permission middleware
        - reusable list/form patterns where possible
        - no duplicated logic across modules where avoidable
        
        ======================================================================
        ROUTES
        ======================================================================
        
        Create proper admin routes for each module, for example:
        - admin/settings/countries
        - admin/settings/awarding-institutions
        - admin/settings/qualification-types
        - admin/settings/fees
        - admin/settings/departments
        
        Use route names consistently and make sure sidebar active states work properly.
        
        ======================================================================
        RELATIONSHIP / INTEGRITY RULES
        ======================================================================
        
        Respect domain relationships:
        - awarding institutions link to countries
        - qualification types link to billing categories if already implemented
        - fee structures link to billing categories
        - departments may link to staff in future/current logic
        
        Before allowing delete/deactivate, inspect whether:
        - records are referenced by applications/invoices/users/etc.
        - hard delete is safe
        - soft delete or is_active toggle is more appropriate
        
        Implement the safer business-friendly behavior.
        
        ======================================================================
        AUDIT LOGGING
        ======================================================================
        
        Use unified audit logs to capture important admin actions:
        - created country
        - updated country
        - deleted/deactivated country
        - created awarding institution
        - updated awarding institution
        - created qualification type
        - updated qualification type
        - created fee structure
        - updated/retired fee structure
        - created department
        - updated department
        
        Do not skip audit coverage for admin settings changes.
        
        ======================================================================
        TESTING REQUIREMENTS
        ======================================================================
        
        Add/update tests for:
        1. menu renders System Settings child items correctly based on permissions
        2. unauthorized admin cannot access settings routes
        3. authorized admin can view list pages
        4. create actions work
        5. update actions work
        6. delete/deactivate behavior works correctly
        7. country ↔ awarding institution relation works
        8. qualification type ↔ billing category relation works if already in model
        9. fee structure history/versioning behavior is preserved
        10. audit logs created for important settings changes
        
        ======================================================================
        OUTPUT FORMAT
        ======================================================================
        
        STEP 1
        Inspect the current admin menu, permissions, and settings-related models/routes/pages.
        Identify what already exists and what must be built or refactored.
        
        STEP 2
        Design the improved settings architecture:
        - sidebar submenu structure
        - permission mapping
        - CRUD module structure
        - delete/deactivate strategy
        - shared page patterns
        
        STEP 3
        Implement the actual code changes:
        - sidebar submenu
        - permissions
        - routes
        - controllers/actions
        - forms/pages
        - role-based visibility
        - tests
        - audit logging
        
        STEP 4
        Show the actual code changes.
        
        STEP 5
        Provide a short completion report confirming:
        - System Settings now has submenus
        - Countries page fully implemented
        - Awarding Institutions page fully implemented
        - Qualification Types page fully implemented
        - Fees page fully implemented
        - Departments page fully implemented
        - actions are role-based
        - routes are protected
        - no placeholders introduced
        - result is production-ready
        
        IMPORTANT
        - Produce actual implementation changes
        - Do not return only a design summary
        - Keep the result production-ready
        - Do not leave “coming soon” pages
        - Do not show unauthorized submenu items to users without permission  $ver1->syncPermissions(Permission::query()->where('guard_name', 'web')->whereIn('name', [
            'dashboard.view',
            'admin.applications.view',
            'admin.verification.view',
        ])->get());

        $ver2 = Role::query()->firstOrCreate(['name' => 'Verification Officer Level 2', 'guard_name' => 'web']);
        $ver2->syncPermissions(Permission::query()->where('guard_name', 'web')->whereIn('name', [
            'dashboard.view',
            'admin.applications.view',
            'admin.verification.view',
        ])->get());

        // Super Admin always has all permissions that exist in the system.
        $superAdmin->syncPermissions(Permission::query()->where('guard_name', 'web')->get());
    }
}

