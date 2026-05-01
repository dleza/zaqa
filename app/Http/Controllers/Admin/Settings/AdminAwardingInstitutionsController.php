<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Domain\Audit\AuditLogService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Settings\StoreAwardingInstitutionRequest;
use App\Http\Requests\Admin\Settings\UpdateAwardingInstitutionRequest;
use App\Models\AwardingInstitution;
use App\Models\Country;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AdminAwardingInstitutionsController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $countryId = $request->query('country_id');
        $active = $request->query('active');

        $institutions = AwardingInstitution::query()
            ->with('country')
            ->when($q !== '', fn ($qq) => $qq->where('name', 'like', "%{$q}%"))
            ->when(is_string($countryId) && $countryId !== '', fn ($qq) => $qq->where('country_id', (int) $countryId))
            ->when($active === '1', fn ($qq) => $qq->where('is_active', true))
            ->when($active === '0', fn ($qq) => $qq->where('is_active', false))
            ->orderBy('country_id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (AwardingInstitution $i) => [
                'id' => $i->id,
                'name' => $i->name,
                'country' => $i->country ? ['id' => $i->country->id, 'name' => $i->country->name, 'iso_code' => $i->country->iso_code] : null,
                'is_active' => (bool) $i->is_active,
                'sort_order' => (int) ($i->sort_order ?? 0),
            ]);

        $countries = Country::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'iso_code'])
            ->map(fn (Country $c) => ['id' => $c->id, 'name' => $c->name, 'iso_code' => $c->iso_code])
            ->values();

        return Inertia::render('Admin/Settings/AwardingInstitutions/Index', [
            'institutions' => $institutions,
            'countries' => $countries,
            'filters' => [
                'q' => $q,
                'country_id' => is_string($countryId) ? $countryId : null,
                'active' => is_string($active) ? $active : null,
            ],
            'can' => [
                'create' => (bool) $request->user()?->can('settings.awarding_institutions.create'),
                'edit' => (bool) $request->user()?->can('settings.awarding_institutions.edit'),
                'delete' => (bool) $request->user()?->can('settings.awarding_institutions.delete'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $countries = Country::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'iso_code'])
            ->map(fn (Country $c) => ['id' => $c->id, 'name' => $c->name, 'iso_code' => $c->iso_code])
            ->values();

        return Inertia::render('Admin/Settings/AwardingInstitutions/Create', [
            'countries' => $countries,
        ]);
    }

    public function store(StoreAwardingInstitutionRequest $request, AuditLogService $audit): RedirectResponse
    {
        $data = $request->validated();

        $institution = DB::transaction(function () use ($data, $request) {
            $inst = AwardingInstitution::query()->create([
                'country_id' => (int) $data['country_id'],
                'name' => $data['name'],
                'is_active' => (bool) $data['is_active'],
                'sort_order' => (int) ($data['sort_order'] ?? 0),
            ]);

            if ($request->hasFile('consent_form')) {
                $disk = config('filesystems.default', 'local');
                $file = $request->file('consent_form');
                $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
                $storedName = 'institution_consent_form_'.Str::random(10).'.'.$ext;
                $directory = sprintf('private/awarding-institutions/%s/consent-form', $inst->id);
                $path = $file->storeAs($directory, $storedName, ['disk' => $disk]);
                $inst->forceFill(['consent_form_path' => $path])->save();
            }

            return $inst;
        });

        $audit->record(
            eventType: 'settings.awarding_institution_created',
            module: 'Settings',
            actionName: 'awarding_institution_created',
            message: 'Awarding institution created.',
            entityType: $institution::class,
            entityId: $institution->id,
            afterState: $institution->toArray(),
            actor: $request->user(),
        );

        return redirect('/admin/settings/awarding-institutions')->with('success', 'Awarding institution created.');
    }

    public function edit(Request $request, AwardingInstitution $awardingInstitution): Response
    {
        $countries = Country::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'iso_code'])
            ->map(fn (Country $c) => ['id' => $c->id, 'name' => $c->name, 'iso_code' => $c->iso_code])
            ->values();

        return Inertia::render('Admin/Settings/AwardingInstitutions/Edit', [
            'institution' => [
                'id' => $awardingInstitution->id,
                'country_id' => (int) $awardingInstitution->country_id,
                'name' => $awardingInstitution->name,
                'is_active' => (bool) $awardingInstitution->is_active,
                'sort_order' => (int) ($awardingInstitution->sort_order ?? 0),
                'has_consent_form' => (bool) $awardingInstitution->has_consent_form,
                'consent_form_url' => $awardingInstitution->consent_form_url,
            ],
            'countries' => $countries,
        ]);
    }

    public function update(UpdateAwardingInstitutionRequest $request, AwardingInstitution $awardingInstitution, AuditLogService $audit): RedirectResponse
    {
        $before = $awardingInstitution->toArray();
        $data = $request->validated();

        DB::transaction(function () use ($request, $data, $awardingInstitution) {
            $awardingInstitution->forceFill([
                'country_id' => (int) $data['country_id'],
                'name' => $data['name'],
                'is_active' => (bool) $data['is_active'],
                'sort_order' => (int) ($data['sort_order'] ?? 0),
            ])->save();

            $disk = config('filesystems.default', 'local');

            if ((bool) ($data['remove_consent_form'] ?? false)) {
                if ($awardingInstitution->consent_form_path) {
                    Storage::disk($disk)->delete($awardingInstitution->consent_form_path);
                }
                $awardingInstitution->forceFill(['consent_form_path' => null])->save();
            }

            if ($request->hasFile('consent_form')) {
                if ($awardingInstitution->consent_form_path) {
                    Storage::disk($disk)->delete($awardingInstitution->consent_form_path);
                }

                $file = $request->file('consent_form');
                $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
                $storedName = 'institution_consent_form_'.Str::random(10).'.'.$ext;
                $directory = sprintf('private/awarding-institutions/%s/consent-form', $awardingInstitution->id);
                $path = $file->storeAs($directory, $storedName, ['disk' => $disk]);
                $awardingInstitution->forceFill(['consent_form_path' => $path])->save();
            }
        });

        $audit->record(
            eventType: 'settings.awarding_institution_updated',
            module: 'Settings',
            actionName: 'awarding_institution_updated',
            message: 'Awarding institution updated.',
            entityType: $awardingInstitution::class,
            entityId: $awardingInstitution->id,
            beforeState: $before,
            afterState: $awardingInstitution->toArray(),
            actor: $request->user(),
        );

        return back()->with('success', 'Awarding institution updated.');
    }

    public function destroy(Request $request, AwardingInstitution $awardingInstitution, AuditLogService $audit): RedirectResponse
    {
        $before = $awardingInstitution->toArray();

        // Safer behavior: deactivation (avoid breaking qualifications referencing this institution).
        $awardingInstitution->forceFill(['is_active' => false])->save();

        $audit->record(
            eventType: 'settings.awarding_institution_deactivated',
            module: 'Settings',
            actionName: 'awarding_institution_deactivated',
            message: 'Awarding institution deactivated.',
            entityType: $awardingInstitution::class,
            entityId: $awardingInstitution->id,
            beforeState: $before,
            afterState: $awardingInstitution->toArray(),
            actor: $request->user(),
        );

        return back()->with('success', 'Awarding institution deactivated.');
    }
}

