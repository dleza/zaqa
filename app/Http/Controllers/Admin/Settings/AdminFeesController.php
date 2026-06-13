<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Domain\Audit\AuditLogService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Settings\StoreFeeStructureRequest;
use App\Http\Requests\Admin\Settings\UpdateFeeStructureRequest;
use App\Models\BillingCategory;
use App\Models\FeeStructure;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminFeesController extends Controller
{
    public function index(Request $request): Response
    {
        $categoryId = $request->query('billing_category_id');
        $active = $request->query('active');

        $fees = FeeStructure::query()
            ->with('billingCategory')
            ->when(is_string($categoryId) && $categoryId !== '', fn ($q) => $q->where('billing_category_id', (int) $categoryId))
            ->when($active === '1', fn ($q) => $q->where('is_active', true))
            ->when($active === '0', fn ($q) => $q->where('is_active', false))
            ->orderByDesc('effective_from')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (FeeStructure $f) => [
                'id' => $f->id,
                'billing_category' => $f->billingCategory ? ['id' => $f->billingCategory->id, 'name' => $f->billingCategory->name] : null,
                'local_fee_cents' => $f->local_fee_cents,
                'foreign_fee_cents' => $f->foreign_fee_cents,
                'currency' => $f->currency,
                'effective_from' => optional($f->effective_from)?->toDateString(),
                'effective_to' => optional($f->effective_to)?->toDateString(),
                'is_active' => (bool) $f->is_active,
                'change_reason' => $f->change_reason,
            ]);

        $categories = BillingCategory::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (BillingCategory $c) => [
                'id' => $c->id,
                'name' => $c->name,
            ])->values();

        return Inertia::render('Admin/Settings/Fees/Index', [
            'fees' => $fees,
            'billing_categories' => $categories,
            'filters' => [
                'billing_category_id' => is_string($categoryId) ? $categoryId : null,
                'active' => is_string($active) ? $active : null,
            ],
            'can' => [
                'create' => (bool) $request->user()?->can('settings.fees.create'),
                'edit' => (bool) $request->user()?->can('settings.fees.edit'),
                'delete' => (bool) $request->user()?->can('settings.fees.delete'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Admin/Settings/Fees/Create', [
            'billing_categories' => BillingCategory::optionsForSelect(),
        ]);
    }

    public function store(StoreFeeStructureRequest $request, AuditLogService $audit): RedirectResponse
    {
        $data = $request->validated();

        $fee = FeeStructure::query()->create([
            'billing_category_id' => (int) $data['billing_category_id'],
            'local_fee_cents' => $data['local_fee_cents'] ?? null,
            'foreign_fee_cents' => $data['foreign_fee_cents'] ?? null,
            'currency' => strtoupper($data['currency']),
            'effective_from' => CarbonImmutable::parse($data['effective_from']),
            'effective_to' => $data['effective_to'] ? CarbonImmutable::parse($data['effective_to']) : null,
            'is_active' => (bool) $data['is_active'],
            'approved_by_user_id' => $request->user()?->id,
            'change_reason' => $data['change_reason'] ?? null,
        ]);

        $audit->record(
            eventType: 'settings.fee_structure_created',
            module: 'Settings',
            actionName: 'fee_structure_created',
            message: 'Fee structure created.',
            entityType: $fee::class,
            entityId: $fee->id,
            afterState: $fee->toArray(),
            actor: $request->user(),
        );

        return redirect('/admin/settings/fees')->with('success', 'Fee structure created.');
    }

    public function edit(Request $request, FeeStructure $feeStructure): Response
    {
        $feeStructure->loadMissing('billingCategory');

        return Inertia::render('Admin/Settings/Fees/Edit', [
            'fee' => [
                'id' => $feeStructure->id,
                'billing_category' => $feeStructure->billingCategory ? ['id' => $feeStructure->billingCategory->id, 'name' => $feeStructure->billingCategory->name] : null,
                'local_fee_cents' => $feeStructure->local_fee_cents,
                'foreign_fee_cents' => $feeStructure->foreign_fee_cents,
                'currency' => $feeStructure->currency,
                'effective_from' => optional($feeStructure->effective_from)?->toDateString(),
                'effective_to' => optional($feeStructure->effective_to)?->toDateString(),
                'is_active' => (bool) $feeStructure->is_active,
                'change_reason' => $feeStructure->change_reason,
            ],
        ]);
    }

    public function update(UpdateFeeStructureRequest $request, FeeStructure $feeStructure, AuditLogService $audit): RedirectResponse
    {
        $before = $feeStructure->toArray();
        $data = $request->validated();

        $feeStructure->forceFill([
            'is_active' => (bool) $data['is_active'],
            'effective_to' => $data['effective_to'] ? CarbonImmutable::parse($data['effective_to']) : null,
            'change_reason' => $data['change_reason'] ?? $feeStructure->change_reason,
        ])->save();

        $audit->record(
            eventType: 'settings.fee_structure_updated',
            module: 'Settings',
            actionName: 'fee_structure_updated',
            message: 'Fee structure updated.',
            entityType: $feeStructure::class,
            entityId: $feeStructure->id,
            beforeState: $before,
            afterState: $feeStructure->toArray(),
            actor: $request->user(),
        );

        return back()->with('success', 'Fee structure updated.');
    }

    public function destroy(Request $request, FeeStructure $feeStructure, AuditLogService $audit): RedirectResponse
    {
        $before = $feeStructure->toArray();

        // Never hard-delete fee history. Retire the structure.
        $feeStructure->forceFill([
            'is_active' => false,
            'effective_to' => $feeStructure->effective_to ?? now(),
        ])->save();

        $audit->record(
            eventType: 'settings.fee_structure_retired',
            module: 'Settings',
            actionName: 'fee_structure_retired',
            message: 'Fee structure retired.',
            entityType: $feeStructure::class,
            entityId: $feeStructure->id,
            beforeState: $before,
            afterState: $feeStructure->toArray(),
            actor: $request->user(),
        );

        return back()->with('success', 'Fee structure retired.');
    }
}

