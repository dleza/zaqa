<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Domain\Audit\AuditLogService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Settings\StoreQualificationTypeRequest;
use App\Http\Requests\Admin\Settings\UpdateQualificationTypeRequest;
use App\Models\BillingCategory;
use App\Models\QualificationType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminQualificationTypesController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $active = $request->query('active');

        $types = QualificationType::query()
            ->with('billingCategory')
            ->when($q !== '', fn ($qq) => $qq->where(function ($w) use ($q) {
                $w->where('name', 'like', "%{$q}%")
                    ->orWhere('zqf_level_code', 'like', "%{$q}%");
            }))
            ->when($active === '1', fn ($qq) => $qq->where('is_active', true))
            ->when($active === '0', fn ($qq) => $qq->where('is_active', false))
            ->orderBy('sort_order')
            ->orderBy('zqf_level_code')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (QualificationType $t) => [
                'id' => $t->id,
                'zqf_level_code' => $t->zqf_level_code,
                'level_label' => $t->level_label,
                'name' => $t->name,
                'billing_category' => $t->billingCategory ? ['id' => $t->billingCategory->id, 'name' => $t->billingCategory->name] : null,
                'requires_subject_results' => (bool) $t->requires_subject_results,
                'is_active' => (bool) $t->is_active,
                'sort_order' => (int) ($t->sort_order ?? 0),
            ]);

        return Inertia::render('Admin/Settings/QualificationTypes/Index', [
            'types' => $types,
            'filters' => [
                'q' => $q,
                'active' => is_string($active) ? $active : null,
            ],
            'can' => [
                'create' => (bool) $request->user()?->can('settings.qualification_types.create'),
                'edit' => (bool) $request->user()?->can('settings.qualification_types.edit'),
                'delete' => (bool) $request->user()?->can('settings.qualification_types.delete'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $categories = BillingCategory::query()->orderBy('name')->get(['id', 'name'])->map(fn (BillingCategory $c) => [
            'id' => $c->id,
            'name' => $c->name,
        ])->values();

        return Inertia::render('Admin/Settings/QualificationTypes/Create', [
            'billing_categories' => $categories,
        ]);
    }

    public function store(StoreQualificationTypeRequest $request, AuditLogService $audit): RedirectResponse
    {
        $data = $request->validated();

        $type = QualificationType::query()->create([
            'zqf_level_code' => strtoupper($data['zqf_level_code']),
            'level_label' => $data['level_label'],
            'name' => $data['name'],
            'short_name' => $data['short_name'] ?: null,
            'description' => $data['description'] ?: null,
            'billing_category_id' => (int) $data['billing_category_id'],
            'requires_subject_results' => (bool) $data['requires_subject_results'],
            'is_active' => (bool) $data['is_active'],
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        $audit->record(
            eventType: 'settings.qualification_type_created',
            module: 'Settings',
            actionName: 'qualification_type_created',
            message: 'Qualification type created.',
            entityType: $type::class,
            entityId: $type->id,
            afterState: $type->toArray(),
            actor: $request->user(),
        );

        return redirect('/admin/settings/qualification-types')->with('success', 'Qualification type created.');
    }

    public function edit(Request $request, QualificationType $qualificationType): Response
    {
        $categories = BillingCategory::query()->orderBy('name')->get(['id', 'name'])->map(fn (BillingCategory $c) => [
            'id' => $c->id,
            'name' => $c->name,
        ])->values();

        return Inertia::render('Admin/Settings/QualificationTypes/Edit', [
            'type' => [
                'id' => $qualificationType->id,
                'zqf_level_code' => $qualificationType->zqf_level_code,
                'level_label' => $qualificationType->level_label,
                'name' => $qualificationType->name,
                'short_name' => $qualificationType->short_name,
                'description' => $qualificationType->description,
                'billing_category_id' => (int) $qualificationType->billing_category_id,
                'requires_subject_results' => (bool) $qualificationType->requires_subject_results,
                'is_active' => (bool) $qualificationType->is_active,
                'sort_order' => (int) ($qualificationType->sort_order ?? 0),
            ],
            'billing_categories' => $categories,
        ]);
    }

    public function update(UpdateQualificationTypeRequest $request, QualificationType $qualificationType, AuditLogService $audit): RedirectResponse
    {
        $before = $qualificationType->toArray();
        $data = $request->validated();

        $qualificationType->forceFill([
            'zqf_level_code' => strtoupper($data['zqf_level_code']),
            'level_label' => $data['level_label'],
            'name' => $data['name'],
            'short_name' => $data['short_name'] ?: null,
            'description' => $data['description'] ?: null,
            'billing_category_id' => (int) $data['billing_category_id'],
            'requires_subject_results' => (bool) $data['requires_subject_results'],
            'is_active' => (bool) $data['is_active'],
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ])->save();

        $audit->record(
            eventType: 'settings.qualification_type_updated',
            module: 'Settings',
            actionName: 'qualification_type_updated',
            message: 'Qualification type updated.',
            entityType: $qualificationType::class,
            entityId: $qualificationType->id,
            beforeState: $before,
            afterState: $qualificationType->toArray(),
            actor: $request->user(),
        );

        return back()->with('success', 'Qualification type updated.');
    }

    public function destroy(Request $request, QualificationType $qualificationType, AuditLogService $audit): RedirectResponse
    {
        $before = $qualificationType->toArray();

        // Safer: deactivation (types are referenced by qualifications/invoices).
        $qualificationType->forceFill(['is_active' => false])->save();

        $audit->record(
            eventType: 'settings.qualification_type_deactivated',
            module: 'Settings',
            actionName: 'qualification_type_deactivated',
            message: 'Qualification type deactivated.',
            entityType: $qualificationType::class,
            entityId: $qualificationType->id,
            beforeState: $before,
            afterState: $qualificationType->toArray(),
            actor: $request->user(),
        );

        return back()->with('success', 'Qualification type deactivated.');
    }
}

