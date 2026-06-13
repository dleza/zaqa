<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Domain\Audit\AuditLogService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Settings\StoreBillingCategoryRequest;
use App\Http\Requests\Admin\Settings\UpdateBillingCategoryRequest;
use App\Models\BillingCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminBillingCategoriesController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $active = $request->query('active');

        $categories = BillingCategory::query()
            ->withCount(['qualificationTypes', 'feeStructures'])
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
            ->through(fn (BillingCategory $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'code' => $c->code,
                'description' => $c->description,
                'local_processing_days' => $c->local_processing_days,
                'foreign_processing_days' => $c->foreign_processing_days,
                'is_active' => (bool) $c->is_active,
                'is_system' => $c->isSystemCategory(),
                'sort_order' => (int) ($c->sort_order ?? 0),
                'qualification_types_count' => (int) ($c->qualification_types_count ?? 0),
                'fee_structures_count' => (int) ($c->fee_structures_count ?? 0),
            ]);

        $user = $request->user();

        return Inertia::render('Admin/Settings/BillingCategories/Index', [
            'categories' => $categories,
            'filters' => [
                'q' => $q,
                'active' => is_string($active) ? $active : null,
            ],
            'can' => [
                'create' => (bool) $user?->can('settings.billing_categories.create'),
                'edit' => (bool) $user?->can('settings.billing_categories.edit'),
                'delete' => (bool) $user?->can('settings.billing_categories.delete'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Admin/Settings/BillingCategories/Create');
    }

    public function store(StoreBillingCategoryRequest $request, AuditLogService $audit): RedirectResponse
    {
        $data = $request->validated();

        $category = BillingCategory::query()->create([
            'name' => $data['name'],
            'code' => strtoupper((string) $data['code']),
            'description' => $data['description'] ?? null,
            'local_processing_days' => $data['local_processing_days'] ?? null,
            'foreign_processing_days' => $data['foreign_processing_days'] ?? null,
            'is_active' => (bool) $data['is_active'],
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        $audit->record(
            eventType: 'settings.billing_category_created',
            module: 'Settings',
            actionName: 'billing_category_created',
            message: 'Billing category created.',
            entityType: $category::class,
            entityId: $category->id,
            afterState: $category->toArray(),
            actor: $request->user(),
        );

        return redirect('/admin/settings/billing-categories')->with('success', 'Billing category created.');
    }

    public function edit(Request $request, BillingCategory $billingCategory): Response
    {
        return Inertia::render('Admin/Settings/BillingCategories/Edit', [
            'category' => [
                'id' => $billingCategory->id,
                'name' => $billingCategory->name,
                'code' => $billingCategory->code,
                'description' => $billingCategory->description,
                'local_processing_days' => $billingCategory->local_processing_days,
                'foreign_processing_days' => $billingCategory->foreign_processing_days,
                'is_active' => (bool) $billingCategory->is_active,
                'is_system' => $billingCategory->isSystemCategory(),
                'sort_order' => (int) ($billingCategory->sort_order ?? 0),
            ],
        ]);
    }

    public function update(
        UpdateBillingCategoryRequest $request,
        BillingCategory $billingCategory,
        AuditLogService $audit,
    ): RedirectResponse {
        $before = $billingCategory->toArray();
        $data = $request->validated();

        $billingCategory->forceFill([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'local_processing_days' => $data['local_processing_days'] ?? null,
            'foreign_processing_days' => $data['foreign_processing_days'] ?? null,
            'is_active' => (bool) $data['is_active'],
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ])->save();

        $audit->record(
            eventType: 'settings.billing_category_updated',
            module: 'Settings',
            actionName: 'billing_category_updated',
            message: 'Billing category updated.',
            entityType: $billingCategory::class,
            entityId: $billingCategory->id,
            beforeState: $before,
            afterState: $billingCategory->toArray(),
            actor: $request->user(),
        );

        return back()->with('success', 'Billing category updated.');
    }

    public function destroy(
        Request $request,
        BillingCategory $billingCategory,
        AuditLogService $audit,
    ): RedirectResponse {
        abort_if($billingCategory->isSystemCategory(), 422, 'This billing category is required by the system and cannot be deactivated.');

        $before = $billingCategory->toArray();

        $billingCategory->forceFill(['is_active' => false])->save();

        $audit->record(
            eventType: 'settings.billing_category_deactivated',
            module: 'Settings',
            actionName: 'billing_category_deactivated',
            message: 'Billing category deactivated.',
            entityType: $billingCategory::class,
            entityId: $billingCategory->id,
            beforeState: $before,
            afterState: $billingCategory->toArray(),
            actor: $request->user(),
        );

        return back()->with('success', 'Billing category deactivated.');
    }
}
