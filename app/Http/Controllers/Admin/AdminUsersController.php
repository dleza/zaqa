<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogService;
use App\Domain\Notifications\OutboundMailService;
use App\Enums\VerificationState;
use App\Http\Controllers\Controller;
use App\Mail\AdminStaffAccountCreatedMail;
use App\Models\Qualification;
use App\Models\QualificationAssignment;
use App\Models\VerificationAssignmentCategoryUser;
use App\Models\Department;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class AdminUsersController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $q = trim((string) $request->query('q', ''));
        $sort = (string) $request->query('sort', 'id');
        $dir = strtolower((string) $request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $allowedSorts = [
            'id' => 'users.id',
            'name' => 'users.name',
            'email' => 'users.email',
            'phone_primary' => 'users.phone_primary',
            'created_at' => 'users.created_at',
            'last_login_at' => 'users.last_login_at',
            'status' => '__status',
        ];

        $usersQuery = User::query()->whereNull('applicant_type')->with('roles');

        if ($q !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';

            $usersQuery->where(function ($query) use ($like) {
                $query
                    ->where('users.name', 'like', $like)
                    ->orWhere('users.email', 'like', $like)
                    ->orWhere('users.phone_primary', 'like', $like);
            });
        }

        $sortColumn = $allowedSorts[$sort] ?? null;
        if (! $sortColumn) {
            $sort = 'id';
            $sortColumn = $allowedSorts['id'];
        }
        if ($sortColumn === '__status') {
            $usersQuery->orderByRaw(
                "CASE WHEN users.disabled_at IS NOT NULL THEN 2 WHEN users.is_active = 1 THEN 0 ELSE 1 END {$dir}"
            );
        } else {
            $usersQuery->orderBy($sortColumn, $dir);
        }

        $users = $usersQuery
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'phone_primary' => $u->phone_primary,
                'is_active' => (bool) $u->is_active,
                'disabled_at' => optional($u->disabled_at)?->toIso8601String(),
                'last_login_at' => optional($u->last_login_at)?->toIso8601String(),
                'created_at' => optional($u->created_at)?->toIso8601String(),
                'roles' => method_exists($u, 'getRoleNames') ? $u->getRoleNames()->values()->all() : [],
            ]);

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'filters' => [
                'q' => $q,
                'sort' => $sort,
                'dir' => $dir,
            ],
        ]);
    }

    public function show(Request $request, User $user): Response
    {
        $this->authorize('viewAny', User::class);

        if ($user->applicant_type !== null) {
            abort(404);
        }

        $user->loadMissing(['department', 'roles']);

        $roles = method_exists($user, 'getRoleNames') ? $user->getRoleNames()->values()->all() : [];
        $permissionNames = method_exists($user, 'getAllPermissions')
            ? $user->getAllPermissions()->pluck('name')->values()->all()
            : [];

        $activity = $this->recentActivity($user);

        return Inertia::render('Admin/Users/Show', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'phone_primary' => $user->phone_primary,
                'phone_secondary' => $user->phone_secondary,
                'is_active' => (bool) $user->is_active,
                'disabled_at' => optional($user->disabled_at)?->toIso8601String(),
                'last_login_at' => optional($user->last_login_at)?->toIso8601String(),
                'created_at' => optional($user->created_at)?->toIso8601String(),
                'roles' => $roles,
                'primary_role' => $roles[0] ?? null,
                'profile_photo_url' => $user->profile_photo_url,
                'department' => $user->department ? ['id' => $user->department->id, 'name' => $user->department->name] : null,
            ],
            'recent_activity' => $activity,
            'stats' => $this->buildStats($user),
            'level1_memberships' => $this->buildLevel1Memberships($user),
            'access_areas' => $this->buildAccessAreas($permissionNames),
            'permission_count' => count($permissionNames),
            'can' => [
                'edit' => (bool) $request->user()?->can('admin.users.edit'),
                'disable' => (bool) $request->user()?->can('admin.users.disable'),
                'resend_login_email' => $this->canResendLoginEmail($request, $user),
            ],
            'resend_login_email_url' => route('admin.users.resend_login_email', $user),
        ]);
    }

    public function edit(User $user): Response
    {
        if ($user->applicant_type !== null) {
            abort(404);
        }

        $user->loadMissing(['department', 'roles']);

        $roles = method_exists($user, 'getRoleNames') ? $user->getRoleNames()->values()->all() : [];

        return Inertia::render('Admin/Users/Edit', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'phone_primary' => $user->phone_primary,
                'phone_secondary' => $user->phone_secondary,
                'is_active' => (bool) $user->is_active,
                'disabled_at' => optional($user->disabled_at)?->toIso8601String(),
                'profile_photo_url' => $user->profile_photo_url,
                'department' => $user->department ? ['id' => $user->department->id, 'name' => $user->department->name] : null,
                'current_role' => $roles[0] ?? null,
            ],
            'roles' => $this->roleOptions(),
            'departments' => $this->departmentOptions(),
        ]);
    }

    public function update(Request $request, User $user, AuditLogService $audit): RedirectResponse
    {
        if ($user->applicant_type !== null) {
            abort(404);
        }

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'phone_primary' => ['nullable', 'string', 'max:50', Rule::unique('users', 'phone_primary')->ignore($user->id)],
            'phone_secondary' => ['nullable', 'string', 'max:50'],
            'role' => ['required', 'string', 'not_in:Applicant'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
        ]);

        /** @var Role|null $role */
        $role = Role::query()->where('guard_name', 'web')->where('name', $validated['role'])->first();
        if (! $role) {
            abort(422, 'Invalid role selected.');
        }

        $existingRoles = method_exists($user, 'getRoleNames') ? $user->getRoleNames()->values()->all() : [];
        $before = [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'name' => $user->name,
            'email' => $user->email,
            'phone_primary' => $user->phone_primary,
            'phone_secondary' => $user->phone_secondary,
            'department_id' => $user->department_id,
            'roles' => $existingRoles,
        ];

        $fullName = trim($validated['first_name'].' '.$validated['last_name']);

        DB::transaction(function () use ($user, $validated, $fullName, $role) {
            $user->forceFill([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'name' => $fullName,
                'email' => $validated['email'],
                'phone_primary' => $validated['phone_primary'] ?: null,
                'phone_secondary' => $validated['phone_secondary'] ?: null,
                'department_id' => $validated['department_id'] ?? null,
            ])->save();

            $user->syncRoles([$role->name]);
        });

        $user->refresh();

        $audit->record(
            eventType: 'admin.managed_user_updated',
            module: 'Identity',
            actionName: 'managed_user_updated',
            message: 'Admin updated a managed user account.',
            entityType: User::class,
            entityId: $user->id,
            beforeState: $before,
            afterState: [
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'name' => $user->name,
                'email' => $user->email,
                'phone_primary' => $user->phone_primary,
                'phone_secondary' => $user->phone_secondary,
                'department_id' => $user->department_id,
                'roles' => method_exists($user, 'getRoleNames') ? $user->getRoleNames()->values()->all() : [],
            ],
            actor: $request->user(),
        );

        return redirect("/admin/users/{$user->id}")
            ->with('success', 'User updated.');
    }

    public function block(Request $request, User $user): RedirectResponse
    {
        if (! $request->user()?->can('admin.users.disable')) {
            abort(403);
        }

        if ($user->applicant_type !== null) {
            abort(404);
        }

        $user->forceFill([
            'disabled_at' => now(),
            'is_active' => false,
        ])->save();

        return back()->with('success', 'User blocked.');
    }

    public function unblock(Request $request, User $user): RedirectResponse
    {
        if (! $request->user()?->can('admin.users.disable')) {
            abort(403);
        }

        if ($user->applicant_type !== null) {
            abort(404);
        }

        $user->forceFill([
            'disabled_at' => null,
            'disabled_reason' => null,
            'is_active' => true,
        ])->save();

        return back()->with('success', 'User unblocked.');
    }

    public function resendLoginEmail(Request $request, User $user, OutboundMailService $mail, AuditLogService $audit): RedirectResponse
    {
        if ($user->applicant_type !== null) {
            abort(404);
        }

        if (! $request->user()?->can('admin.users.edit')) {
            abort(403);
        }

        if ($user->last_login_at !== null) {
            return back()->withErrors([
                'resend_login_email' => 'This account has already been used to sign in. Login details cannot be resent.',
            ]);
        }

        $email = trim((string) ($user->email ?? ''));
        if ($email === '') {
            return back()->withErrors([
                'resend_login_email' => 'This user does not have an email address on file.',
            ]);
        }

        $roles = method_exists($user, 'getRoleNames') ? $user->getRoleNames()->values()->all() : [];
        $roleName = (string) ($roles[0] ?? 'Staff');

        $generatedPassword = Str::password(14, true, true, false, false);
        $user->forceFill(['password' => $generatedPassword])->save();

        $this->queueStaffWelcomeEmail($mail, $user, $generatedPassword, $roleName);

        $audit->record(
            eventType: 'admin.managed_user_login_email_resent',
            module: 'Admin',
            actionName: 'managed_user_login_email_resent',
            message: 'Staff login details email resent by administrator.',
            entityType: User::class,
            entityId: (int) $user->id,
            metadata: [
                'user_id' => (int) $user->id,
                'email' => $email,
                'role' => $roleName,
            ],
            actor: $request->user(),
        );

        return back()
            ->with('success', 'Login details have been resent to the user.')
            ->with('generated_password', $generatedPassword)
            ->with('generated_password_for', $email);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Admin/Users/Create', [
            'roles' => $this->roleOptions(),
            'departments' => $this->departmentOptions(),
        ]);
    }

    public function store(Request $request, OutboundMailService $mail): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'phone_primary' => ['nullable', 'string', 'max:50', 'unique:users,phone_primary'],
            'role' => ['required', 'string', 'not_in:Applicant'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
        ]);

        /** @var Role|null $role */
        $role = Role::query()->where('guard_name', 'web')->where('name', $validated['role'])->first();
        if (! $role) {
            abort(422, 'Invalid role selected.');
        }

        $generatedPassword = Str::password(14, true, true, false, false);
        $fullName = trim($validated['first_name'].' '.$validated['last_name']);

        $user = DB::transaction(function () use ($validated, $role, $generatedPassword, $fullName) {
            $u = User::query()->create([
                'uuid' => (string) Str::uuid(),
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'name' => $fullName,
                'email' => $validated['email'],
                'phone_primary' => $validated['phone_primary'] ?: null,
                'phone_secondary' => null,
                'department_id' => $validated['department_id'] ?? null,
                'password' => $generatedPassword, // hashed via cast
                'applicant_type' => null,
                'is_active' => true,
            ]);

            $u->forceFill([
                'email_verified_at' => now(),
                'phone_verified_at' => null,
            ])->save();

            $u->syncRoles([$role->name]);

            return $u;
        });

        $this->queueStaffWelcomeEmail($mail, $user, $generatedPassword, $role->name);

        return redirect('/admin/users')
            ->with('success', 'User created. Login details have been emailed to the user.')
            ->with('generated_password', $generatedPassword)
            ->with('generated_password_for', $user->email);
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{name:string}>
     */
    private function roleOptions()
    {
        return Role::query()
            ->where('guard_name', 'web')
            ->where('name', '!=', 'Applicant')
            ->orderBy('name')
            ->get(['name'])
            ->map(fn (Role $r) => ['name' => $r->name])
            ->values();
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{id:int,name:string}>
     */
    private function departmentOptions()
    {
        return Department::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Department $d) => ['id' => $d->id, 'name' => $d->name])
            ->values();
    }

    /**
     * @return array<int, array{id:int,module:string,message:string,created_at:?string,url:?string,actor_name_snapshot:?string}>
     */
    private function recentActivity(User $user): array
    {
        return AuditLog::query()
            ->where(function ($query) use ($user) {
                $query->where('actor_user_id', $user->id)
                    ->orWhere(function ($inner) use ($user) {
                        $inner->where('entity_type', User::class)
                            ->where('entity_id', $user->id);
                    });
            })
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'module', 'message', 'entity_type', 'entity_id', 'created_at', 'actor_name_snapshot'])
            ->map(function (AuditLog $log) {
                return [
                    'id' => (int) $log->id,
                    'module' => (string) $log->module,
                    'message' => (string) $log->message,
                    'created_at' => optional($log->created_at)->toIso8601String(),
                    'url' => $this->activityUrlFor((string) ($log->entity_type ?? ''), $log->entity_id ? (int) $log->entity_id : null),
                    'actor_name_snapshot' => $log->actor_name_snapshot,
                ];
            })
            ->values()
            ->all();
    }

    private function buildStats(User $user): array
    {
        $isLevel1 = $user->hasRole('Verification Officer Level 1');
        $isLevel2 = $user->hasRole('Verification Officer Level 2');
        $isSuper = $user->hasRole('Super Admin');

        $stats = [
            'role' => $isSuper ? 'super_admin' : ($isLevel2 ? 'level2' : ($isLevel1 ? 'level1' : 'staff')),
            'cards' => [],
        ];

        if ($isLevel1) {
            $pending = Qualification::query()
                ->where('assigned_verifier_id', $user->id)
                ->whereIn('verification_state', [
                    VerificationState::AssignedToLevel1->value,
                    VerificationState::UnderLevel1Review->value,
                ])
                ->count();

            $completedToL2 = Qualification::query()
                ->where('assigned_verifier_id', $user->id)
                ->where('verification_state', VerificationState::UnderLevel2Review->value)
                ->count();

            $reviewedTotal = Qualification::query()
                ->where('assigned_verifier_id', $user->id)
                ->whereNotNull('reviewed_at')
                ->count();

            $avgSeconds = Qualification::query()
                ->where('assigned_verifier_id', $user->id)
                ->whereNotNull('assigned_at')
                ->whereNotNull('reviewed_at')
                ->selectRaw('avg(timestampdiff(second, assigned_at, reviewed_at)) as avg_s')
                ->value('avg_s');

            $avgMinutes = $avgSeconds !== null ? (int) round(((float) $avgSeconds) / 60) : null;

            $categoryCount = VerificationAssignmentCategoryUser::query()
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->count();

            $stats['cards'] = [
                ['label' => 'Pending reviews', 'value' => $pending],
                ['label' => 'Sent to Level 2', 'value' => $completedToL2],
                ['label' => 'Reviewed (total)', 'value' => $reviewedTotal],
                ['label' => 'Avg turnaround (mins)', 'value' => $avgMinutes ?? '—'],
                ['label' => 'Assigned categories', 'value' => $categoryCount],
            ];
        } elseif ($isLevel2) {
            $pendingOwned = Qualification::query()
                ->where('verification_state', VerificationState::UnderLevel2Review->value)
                ->where('level2_review_owner_id', $user->id)
                ->count();

            $autoVerifiedPending = Qualification::query()
                ->where('verification_state', VerificationState::AutoVerifiedPendingLevel2->value)
                ->count();

            $locks = Qualification::query()
                ->where('verification_state', VerificationState::AutoVerifiedPendingLevel2->value)
                ->where('level2_review_locked_by', $user->id)
                ->count();

            $assignmentsMade = QualificationAssignment::query()
                ->where('assigned_by_user_id', $user->id)
                ->count();

            $stats['cards'] = [
                ['label' => 'Pending Level 2 (owned)', 'value' => $pendingOwned],
                ['label' => 'Auto-verified pending', 'value' => $autoVerifiedPending],
                ['label' => 'My active locks', 'value' => $locks],
                ['label' => 'Assignments made', 'value' => $assignmentsMade],
            ];
        } elseif ($isSuper) {
            $stats['cards'] = [
                ['label' => 'Users', 'value' => User::query()->count()],
                ['label' => 'Active reviews', 'value' => Qualification::query()->whereIn('verification_state', [
                    VerificationState::AssignedToLevel1->value,
                    VerificationState::UnderLevel1Review->value,
                    VerificationState::UnderLevel2Review->value,
                ])->count()],
                ['label' => 'Auto-verified pending', 'value' => Qualification::query()->where('verification_state', VerificationState::AutoVerifiedPendingLevel2->value)->count()],
                ['label' => 'Assignments made', 'value' => QualificationAssignment::query()->where('assigned_by_user_id', $user->id)->count()],
            ];
        }

        return $stats;
    }

    private function buildLevel1Memberships(User $user): array
    {
        if (! $user->hasRole('Verification Officer Level 1')) {
            return [];
        }

        return VerificationAssignmentCategoryUser::query()
            ->with([
                'category' => fn ($q) => $q->withCount(['countries', 'awardingInstitutions']),
            ])
            ->where('user_id', $user->id)
            ->orderByDesc('is_active')
            ->get()
            ->map(function (VerificationAssignmentCategoryUser $membership) {
                $category = $membership->category;

                return [
                    'id' => (int) $membership->id,
                    'category' => $category ? [
                        'id' => (int) $category->id,
                        'name' => (string) $category->name,
                        'type' => (string) $category->type,
                        'mapped_count' => (string) $category->type === 'foreign_country'
                            ? (int) ($category->countries_count ?? 0)
                            : (int) ($category->awarding_institutions_count ?? 0),
                        'url' => route('admin.verification.assignment_categories.show', ['assignmentCategory' => $category->id]),
                    ] : null,
                    'is_active' => (bool) $membership->is_active,
                    'is_available' => (bool) $membership->is_available,
                    'unavailable_reason' => $membership->unavailable_reason,
                    'unavailable_until' => optional($membership->unavailable_until)?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $permissionNames
     * @return array<int, array{key:string,label:string,klass:string}>
     */
    private function buildAccessAreas(array $permissionNames): array
    {
        $permissions = collect($permissionNames);
        $areas = [];
        $add = function (string $key, string $label, string $klass = 'zaqa-badge-info') use (&$areas) {
            $areas[] = compact('key', 'label', 'klass');
        };

        if ($permissions->contains(fn ($name) => in_array($name, ['admin.verification.view', 'verification.pool.view', 'verification.level1.process', 'verification.level2.review'], true))) {
            $add('verification', 'Verification');
        }
        if ($permissions->contains(fn ($name) => in_array($name, ['learner_records.view', 'learner_records.import'], true))) {
            $add('learner_records', 'Learner Records');
        }
        if ($permissions->contains(fn ($name) => in_array($name, ['institution_api.manage', 'institution_api.logs.view', 'institution_api.docs.view'], true))) {
            $add('integrations', 'Integrations');
        }
        if ($permissions->contains(fn ($name) => in_array($name, ['admin.finance.view', 'finance.dashboard.view', 'finance.payments.view'], true))) {
            $add('finance', 'Finance');
        }
        if ($permissions->contains(fn ($name) => in_array($name, ['reports.view', 'reports.sla.view'], true))) {
            $add('reports', 'Reports');
        }
        if ($permissions->contains(fn ($name) => str_starts_with($name, 'settings.') || $name === 'admin.reference_data.manage')) {
            $add('settings', 'System Settings');
        }
        if ($permissions->contains(fn ($name) => in_array($name, ['admin.users.view', 'admin.users.create', 'admin.users.edit', 'admin.users.disable', 'admin.roles.manage', 'admin.roles.view'], true))) {
            $add('users', 'User/Admin Mgmt');
        }

        if ($areas === []) {
            $add('basic', 'Basic access', 'zaqa-badge-secondary');
        }

        return array_slice($areas, 0, 7);
    }

    private function activityUrlFor(string $entityType, ?int $entityId): ?string
    {
        if (! $entityId) {
            return null;
        }

        return match ($entityType) {
            \App\Models\Qualification::class => route('admin.verification.qualifications.show', ['qualification' => $entityId]),
            \App\Models\Application::class => route('admin.verification.applications.show', ['application' => $entityId]),
            \App\Models\Payment::class => route('admin.finance.payments.show', ['payment' => $entityId]),
            \App\Models\InstitutionApiClient::class => '/admin/integrations/institution-api-clients/'.$entityId,
            \App\Models\AwardingInstitution::class => '/admin/settings/awarding-institutions/'.$entityId,
            \App\Models\LearnerRecordImport::class => '/admin/learner-records/imports/'.$entityId,
            default => null,
        };
    }

    private function canResendLoginEmail(Request $request, User $user): bool
    {
        if (! $request->user()?->can('admin.users.edit')) {
            return false;
        }

        if ($user->last_login_at !== null) {
            return false;
        }

        return trim((string) ($user->email ?? '')) !== '';
    }

    private function queueStaffWelcomeEmail(OutboundMailService $mail, User $user, string $plainTextPassword, string $roleName): void
    {
        $email = trim((string) ($user->email ?? ''));
        if ($email === '') {
            return;
        }

        $mail->queue(
            mailable: new AdminStaffAccountCreatedMail(
                recipientName: (string) $user->name,
                email: $email,
                plainTextPassword: $plainTextPassword,
                roleName: $roleName,
                loginUrl: route('login'),
            ),
            to: $email,
            logContext: [
                'user_id' => $user->id,
                'application_id' => null,
                'email' => $email,
                'subject' => 'Your ZAQA staff account',
                'template_key' => 'admin_staff_account_created',
            ],
        );
    }
}
