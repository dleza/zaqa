<?php

namespace App\Http\Controllers\Admin\Verification;

use App\Http\Controllers\Controller;
use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\Qualification;
use App\Models\User;
use App\Models\VerificationAssignmentCategory;
use App\Models\VerificationAssignmentCategoryUser;
use App\Enums\VerificationState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AdminVerificationAssignmentCategoriesController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('verification.assign'), 403);

        $q = trim((string) $request->query('q', ''));
        $type = trim((string) $request->query('type', ''));
        $active = $request->query('active');

        $rows = VerificationAssignmentCategory::query()
            ->with(['country:id,name,iso_code', 'awardingInstitution:id,name', 'lastAssignedUser:id,name'])
            ->withCount(['memberships' => fn ($m) => $m->where('is_active', true)])
            ->when($q !== '', fn ($qq) => $qq->where('name', 'like', '%'.$q.'%'))
            ->when(in_array($type, ['foreign_country', 'local_institution'], true), fn ($qq) => $qq->where('type', $type))
            ->when($active === '1', fn ($qq) => $qq->where('is_active', true))
            ->when($active === '0', fn ($qq) => $qq->where('is_active', false))
            ->orderBy('type')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (VerificationAssignmentCategory $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'type' => $c->type,
                'is_active' => (bool) $c->is_active,
                'country' => $c->country ? ['id' => $c->country->id, 'name' => $c->country->name, 'iso_code' => $c->country->iso_code] : null,
                'awarding_institution' => $c->awardingInstitution ? ['id' => $c->awardingInstitution->id, 'name' => $c->awardingInstitution->name] : null,
                'members_count' => (int) ($c->memberships_count ?? 0),
                'last_assigned_user' => $c->lastAssignedUser ? ['id' => $c->lastAssignedUser->id, 'name' => $c->lastAssignedUser->name] : null,
                'last_assigned_at' => optional($c->last_assigned_at)->toIso8601String(),
                'show_url' => route('admin.verification.assignment_categories.show', ['assignmentCategory' => $c->id]),
                'edit_url' => route('admin.verification.assignment_categories.edit', ['assignmentCategory' => $c->id]),
            ]);

        return Inertia::render('Admin/Verification/AssignmentCategories/Index', [
            'categories' => $rows,
            'filters' => [
                'q' => $q,
                'type' => $type !== '' ? $type : null,
                'active' => is_string($active) ? $active : null,
            ],
            'can' => [
                'manage' => true,
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('verification.assign'), 403);

        return Inertia::render('Admin/Verification/AssignmentCategories/Create', [
            'countries' => Country::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'iso_code'])
                ->map(fn (Country $c) => ['id' => $c->id, 'name' => $c->name, 'iso_code' => $c->iso_code])->values(),
            'institutions' => AwardingInstitution::query()->orderBy('name')->get(['id', 'name', 'is_active'])
                ->map(fn (AwardingInstitution $i) => ['id' => $i->id, 'name' => $i->name, 'is_active' => (bool) $i->is_active])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('verification.assign'), 403);

        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(['foreign_country', 'local_institution'])],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'awarding_institution_id' => ['nullable', 'integer', 'exists:awarding_institutions,id'],
            'name' => ['nullable', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ]);

        $type = (string) $validated['type'];
        $countryId = isset($validated['country_id']) ? (int) $validated['country_id'] : null;
        $instId = isset($validated['awarding_institution_id']) ? (int) $validated['awarding_institution_id'] : null;

        if ($type === 'foreign_country' && ! $countryId) {
            return back()->withErrors(['country_id' => 'Country is required for foreign country categories.']);
        }
        if ($type === 'local_institution' && ! $instId) {
            return back()->withErrors(['awarding_institution_id' => 'Awarding institution is required for local institution categories.']);
        }

        $name = trim((string) ($validated['name'] ?? ''));
        if ($name === '') {
            if ($type === 'foreign_country') {
                $country = Country::query()->find($countryId);
                $name = $country ? (string) $country->name : 'Foreign country';
            } else {
                $inst = AwardingInstitution::query()->find($instId);
                $name = $inst ? (string) $inst->name : 'Local institution';
            }
        }

        $uniqueRule = $type === 'foreign_country'
            ? Rule::unique('verification_assignment_categories')->where(fn ($q) => $q->where('type', $type)->where('country_id', $countryId))
            : Rule::unique('verification_assignment_categories')->where(fn ($q) => $q->where('type', $type)->where('awarding_institution_id', $instId));

        $request->validate([
            'type' => [$uniqueRule],
        ], ['type.unique' => 'A category already exists for this selection.']);

        $category = VerificationAssignmentCategory::query()->create([
            'name' => $name,
            'type' => $type,
            'country_id' => $type === 'foreign_country' ? $countryId : null,
            'awarding_institution_id' => $type === 'local_institution' ? $instId : null,
            'is_active' => (bool) $validated['is_active'],
            'metadata' => null,
        ]);

        return redirect()->route('admin.verification.assignment_categories.show', ['assignmentCategory' => $category->id])
            ->with('success', 'Assignment category created.');
    }

    public function show(Request $request, VerificationAssignmentCategory $assignmentCategory): Response
    {
        abort_unless($request->user()?->can('verification.assign'), 403);

        $assignmentCategory->loadMissing([
            'country:id,name,iso_code',
            'awardingInstitution:id,name,is_active',
            'lastAssignedUser:id,name',
            'memberships.user:id,name,email,is_active',
        ]);

        $activeStates = [
            VerificationState::AssignedToLevel1->value,
            VerificationState::UnderLevel1Review->value,
        ];

        $memberIds = $assignmentCategory->memberships->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $workloads = $memberIds === []
            ? []
            : Qualification::query()
                ->whereIn('assigned_verifier_id', $memberIds)
                ->whereIn('verification_state', $activeStates)
                ->selectRaw('assigned_verifier_id, count(*) as c')
                ->groupBy('assigned_verifier_id')
                ->pluck('c', 'assigned_verifier_id')
                ->map(fn ($v) => (int) $v)
                ->all();

        $level1Users = User::query()
            ->whereNull('applicant_type')
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('name', 'Verification Officer Level 1'))
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])
            ->values();

        return Inertia::render('Admin/Verification/AssignmentCategories/Show', [
            'category' => [
                'id' => $assignmentCategory->id,
                'name' => $assignmentCategory->name,
                'type' => $assignmentCategory->type,
                'is_active' => (bool) $assignmentCategory->is_active,
                'country' => $assignmentCategory->country ? ['id' => $assignmentCategory->country->id, 'name' => $assignmentCategory->country->name, 'iso_code' => $assignmentCategory->country->iso_code] : null,
                'awarding_institution' => $assignmentCategory->awardingInstitution ? [
                    'id' => $assignmentCategory->awardingInstitution->id,
                    'name' => $assignmentCategory->awardingInstitution->name,
                    'is_active' => (bool) $assignmentCategory->awardingInstitution->is_active,
                ] : null,
                'last_assigned_user' => $assignmentCategory->lastAssignedUser ? ['id' => $assignmentCategory->lastAssignedUser->id, 'name' => $assignmentCategory->lastAssignedUser->name] : null,
                'last_assigned_at' => optional($assignmentCategory->last_assigned_at)->toIso8601String(),
                'created_at' => optional($assignmentCategory->created_at)->toIso8601String(),
                'updated_at' => optional($assignmentCategory->updated_at)->toIso8601String(),
            ],
            'memberships' => $assignmentCategory->memberships
                ->sortBy(fn (VerificationAssignmentCategoryUser $m) => [$m->is_active ? 0 : 1, $m->user?->name ?? ''])
                ->values()
                ->map(fn (VerificationAssignmentCategoryUser $m) => [
                    'id' => $m->id,
                    'user' => $m->user ? ['id' => $m->user->id, 'name' => $m->user->name, 'email' => $m->user->email, 'is_active' => (bool) $m->user->is_active] : null,
                    'is_active' => (bool) $m->is_active,
                    'is_available' => (bool) $m->is_available,
                    'unavailable_reason' => $m->unavailable_reason,
                    'unavailable_until' => optional($m->unavailable_until)->toIso8601String(),
                    'priority' => $m->priority,
                    'last_assigned_at' => optional($m->last_assigned_at)->toIso8601String(),
                    'workload_active' => $m->user_id ? (int) ($workloads[(string) $m->user_id] ?? 0) : 0,
                ]),
            'level1_users' => $level1Users,
            'links' => [
                'index' => route('admin.verification.assignment_categories.index'),
                'edit' => route('admin.verification.assignment_categories.edit', ['assignmentCategory' => $assignmentCategory->id]),
                'deactivate' => route('admin.verification.assignment_categories.deactivate', ['assignmentCategory' => $assignmentCategory->id]),
                'reactivate' => route('admin.verification.assignment_categories.reactivate', ['assignmentCategory' => $assignmentCategory->id]),
                'add_member' => route('admin.verification.assignment_categories.members.store', ['assignmentCategory' => $assignmentCategory->id]),
            ],
        ]);
    }

    public function edit(Request $request, VerificationAssignmentCategory $assignmentCategory): Response
    {
        abort_unless($request->user()?->can('verification.assign'), 403);

        $assignmentCategory->loadMissing(['country:id,name,iso_code', 'awardingInstitution:id,name,is_active']);

        return Inertia::render('Admin/Verification/AssignmentCategories/Edit', [
            'category' => [
                'id' => $assignmentCategory->id,
                'name' => $assignmentCategory->name,
                'type' => $assignmentCategory->type,
                'is_active' => (bool) $assignmentCategory->is_active,
                'country_id' => $assignmentCategory->country_id,
                'awarding_institution_id' => $assignmentCategory->awarding_institution_id,
            ],
            'countries' => Country::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'iso_code'])
                ->map(fn (Country $c) => ['id' => $c->id, 'name' => $c->name, 'iso_code' => $c->iso_code])->values(),
            'institutions' => AwardingInstitution::query()->orderBy('name')->get(['id', 'name', 'is_active'])
                ->map(fn (AwardingInstitution $i) => ['id' => $i->id, 'name' => $i->name, 'is_active' => (bool) $i->is_active])->values(),
        ]);
    }

    public function update(Request $request, VerificationAssignmentCategory $assignmentCategory): RedirectResponse
    {
        abort_unless($request->user()?->can('verification.assign'), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ]);

        $assignmentCategory->forceFill([
            'name' => (string) $validated['name'],
            'is_active' => (bool) $validated['is_active'],
        ])->save();

        return back()->with('success', 'Assignment category updated.');
    }

    public function deactivate(Request $request, VerificationAssignmentCategory $assignmentCategory): RedirectResponse
    {
        abort_unless($request->user()?->can('verification.assign'), 403);
        $assignmentCategory->forceFill(['is_active' => false])->save();
        return back()->with('success', 'Category deactivated.');
    }

    public function reactivate(Request $request, VerificationAssignmentCategory $assignmentCategory): RedirectResponse
    {
        abort_unless($request->user()?->can('verification.assign'), 403);
        $assignmentCategory->forceFill(['is_active' => true])->save();
        return back()->with('success', 'Category reactivated.');
    }

    public function storeMember(Request $request, VerificationAssignmentCategory $assignmentCategory): RedirectResponse
    {
        abort_unless($request->user()?->can('verification.assign'), 403);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);

        $user = User::query()->findOrFail((int) $validated['user_id']);
        if (! $user->hasRole('Verification Officer Level 1')) {
            return back()->withErrors(['user_id' => 'Only Level 1 officers can be assigned to categories.']);
        }

        VerificationAssignmentCategoryUser::query()->updateOrCreate(
            [
                'verification_assignment_category_id' => (int) $assignmentCategory->id,
                'user_id' => (int) $user->id,
            ],
            [
                'is_active' => true,
                'is_available' => true,
                'unavailable_reason' => null,
                'unavailable_until' => null,
                'priority' => $validated['priority'] ?? null,
            ],
        );

        return back()->with('success', 'Officer added to category.');
    }

    public function updateMember(Request $request, VerificationAssignmentCategory $assignmentCategory, VerificationAssignmentCategoryUser $member): RedirectResponse
    {
        abort_unless($request->user()?->can('verification.assign'), 403);
        abort_unless((int) $member->verification_assignment_category_id === (int) $assignmentCategory->id, 404);

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
            'is_available' => ['required', 'boolean'],
            'unavailable_reason' => ['nullable', 'string', 'max:255'],
            'unavailable_until' => ['nullable', 'date'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);

        $member->forceFill([
            'is_active' => (bool) $validated['is_active'],
            'is_available' => (bool) $validated['is_available'],
            'unavailable_reason' => $validated['unavailable_reason'] ? (string) $validated['unavailable_reason'] : null,
            'unavailable_until' => $validated['unavailable_until'] ? (string) $validated['unavailable_until'] : null,
            'priority' => array_key_exists('priority', $validated) ? $validated['priority'] : null,
        ])->save();

        return back()->with('success', 'Officer membership updated.');
    }

    public function destroyMember(Request $request, VerificationAssignmentCategory $assignmentCategory, VerificationAssignmentCategoryUser $member): RedirectResponse
    {
        abort_unless($request->user()?->can('verification.assign'), 403);
        abort_unless((int) $member->verification_assignment_category_id === (int) $assignmentCategory->id, 404);

        $member->delete();

        return back()->with('success', 'Officer removed from category.');
    }
}

