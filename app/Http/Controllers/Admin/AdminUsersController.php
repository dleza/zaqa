<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\AuditLog;
use App\Models\User;
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

        $users = User::query()
            ->whereNull('applicant_type')
            ->orderByDesc('id')
            ->paginate(20)
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
        ]);
    }

    public function show(Request $request, User $user): Response
    {
        $this->authorize('viewAny', User::class);

        if ($user->applicant_type !== null) {
            abort(404);
        }

        $user->loadMissing(['department']);

        $activity = AuditLog::query()
            ->where('actor_user_id', $user->id)
            ->latest('id')
            ->limit(25)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'event_type' => $log->event_type,
                'module' => $log->module,
                'message' => $log->message,
                'ip_address' => $log->ip_address,
                'created_at' => optional($log->created_at)?->toIso8601String(),
            ]);

        return Inertia::render('Admin/Users/Show', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'phone_primary' => $user->phone_primary,
                'is_active' => (bool) $user->is_active,
                'disabled_at' => optional($user->disabled_at)?->toIso8601String(),
                'last_login_at' => optional($user->last_login_at)?->toIso8601String(),
                'created_at' => optional($user->created_at)?->toIso8601String(),
                'roles' => method_exists($user, 'getRoleNames') ? $user->getRoleNames()->values()->all() : [],
                'department' => $user->department ? ['id' => $user->department->id, 'name' => $user->department->name] : null,
            ],
            'activity' => $activity,
        ]);
    }

    public function block(Request $request, User $user): \Illuminate\Http\RedirectResponse
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

    public function unblock(Request $request, User $user): \Illuminate\Http\RedirectResponse
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

    public function create(Request $request): Response
    {
        $roles = Role::query()
            ->where('guard_name', 'web')
            ->where('name', '!=', 'Applicant')
            ->orderBy('name')
            ->get(['name'])
            ->map(fn (Role $r) => ['name' => $r->name])
            ->values();

        $departments = Department::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Department $d) => ['id' => $d->id, 'name' => $d->name])
            ->values();

        return Inertia::render('Admin/Users/Create', [
            'roles' => $roles,
            'departments' => $departments,
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
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
                'email_verified_at' => now(),
                'phone_verified_at' => null,
            ]);

            $u->syncRoles([$role->name]);

            return $u;
        });

        return redirect('/admin/users')
            ->with('success', 'User created.')
            ->with('generated_password', $generatedPassword)
            ->with('generated_password_for', $user->email);
    }
}

