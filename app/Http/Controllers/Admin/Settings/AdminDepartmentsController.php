<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Domain\Audit\AuditLogService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Settings\StoreDepartmentRequest;
use App\Http\Requests\Admin\Settings\UpdateDepartmentRequest;
use App\Models\Department;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminDepartmentsController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $active = $request->query('active');

        $departments = Department::query()
            ->when($q !== '', fn ($qq) => $qq->where(function ($w) use ($q) {
                $w->where('name', 'like', "%{$q}%")
                    ->orWhere('code', 'like', "%{$q}%");
            }))
            ->when($active === '1', fn ($qq) => $qq->where('is_active', true))
            ->when($active === '0', fn ($qq) => $qq->where('is_active', false))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Department $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'code' => $d->code,
                'is_active' => (bool) $d->is_active,
                'sort_order' => (int) ($d->sort_order ?? 0),
            ]);

        return Inertia::render('Admin/Settings/Departments/Index', [
            'departments' => $departments,
            'filters' => [
                'q' => $q,
                'active' => is_string($active) ? $active : null,
            ],
            'can' => [
                'create' => (bool) $request->user()?->can('settings.departments.create'),
                'edit' => (bool) $request->user()?->can('settings.departments.edit'),
                'delete' => (bool) $request->user()?->can('settings.departments.delete'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Admin/Settings/Departments/Create');
    }

    public function store(StoreDepartmentRequest $request, AuditLogService $audit): RedirectResponse
    {
        $data = $request->validated();

        $dept = Department::query()->create([
            'name' => $data['name'],
            'code' => $data['code'] ?: null,
            'description' => $data['description'] ?: null,
            'is_active' => (bool) $data['is_active'],
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        $audit->record(
            eventType: 'settings.department_created',
            module: 'Settings',
            actionName: 'department_created',
            message: 'Department created.',
            entityType: $dept::class,
            entityId: $dept->id,
            afterState: $dept->toArray(),
            actor: $request->user(),
        );

        return redirect('/admin/settings/departments')->with('success', 'Department created.');
    }

    public function edit(Request $request, Department $department): Response
    {
        return Inertia::render('Admin/Settings/Departments/Edit', [
            'department' => [
                'id' => $department->id,
                'name' => $department->name,
                'code' => $department->code,
                'description' => $department->description,
                'is_active' => (bool) $department->is_active,
                'sort_order' => (int) ($department->sort_order ?? 0),
            ],
        ]);
    }

    public function update(UpdateDepartmentRequest $request, Department $department, AuditLogService $audit): RedirectResponse
    {
        $before = $department->toArray();
        $data = $request->validated();

        $department->forceFill([
            'name' => $data['name'],
            'code' => $data['code'] ?: null,
            'description' => $data['description'] ?: null,
            'is_active' => (bool) $data['is_active'],
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ])->save();

        $audit->record(
            eventType: 'settings.department_updated',
            module: 'Settings',
            actionName: 'department_updated',
            message: 'Department updated.',
            entityType: $department::class,
            entityId: $department->id,
            beforeState: $before,
            afterState: $department->toArray(),
            actor: $request->user(),
        );

        return back()->with('success', 'Department updated.');
    }

    public function destroy(Request $request, Department $department, AuditLogService $audit): RedirectResponse
    {
        $before = $department->toArray();

        $inUse = $department->users()->exists();
        if ($inUse) {
            $department->forceFill(['is_active' => false])->save();

            $audit->record(
                eventType: 'settings.department_deactivated',
                module: 'Settings',
                actionName: 'department_deactivated',
                message: 'Department deactivated (in use).',
                entityType: $department::class,
                entityId: $department->id,
                beforeState: $before,
                afterState: $department->toArray(),
                actor: $request->user(),
                metadata: ['in_use' => true],
            );

            return back()->with('success', 'Department is in use; it was deactivated instead of deleted.');
        }

        $department->delete();

        $audit->record(
            eventType: 'settings.department_deleted',
            module: 'Settings',
            actionName: 'department_deleted',
            message: 'Department deleted.',
            entityType: Department::class,
            entityId: (int) $before['id'],
            beforeState: $before,
            actor: $request->user(),
        );

        return back()->with('success', 'Department deleted.');
    }
}

