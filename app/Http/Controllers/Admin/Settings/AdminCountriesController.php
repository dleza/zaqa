<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Domain\Audit\AuditLogService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Settings\StoreCountryRequest;
use App\Http\Requests\Admin\Settings\UpdateCountryRequest;
use App\Models\Country;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminCountriesController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $active = $request->query('active');

        $countries = Country::query()
            ->when($q !== '', fn ($qq) => $qq->where(function ($w) use ($q) {
                $w->where('name', 'like', "%{$q}%")
                    ->orWhere('iso_code', 'like', "%{$q}%");
            }))
            ->when($active === '1', fn ($qq) => $qq->where('is_active', true))
            ->when($active === '0', fn ($qq) => $qq->where('is_active', false))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Country $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'iso_code' => $c->iso_code,
                'is_active' => (bool) $c->is_active,
                'sort_order' => (int) ($c->sort_order ?? 0),
            ]);

        return Inertia::render('Admin/Settings/Countries/Index', [
            'countries' => $countries,
            'filters' => [
                'q' => $q,
                'active' => is_string($active) ? $active : null,
            ],
            'can' => [
                'create' => (bool) $request->user()?->can('settings.countries.create'),
                'edit' => (bool) $request->user()?->can('settings.countries.edit'),
                'delete' => (bool) $request->user()?->can('settings.countries.delete'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Admin/Settings/Countries/Create');
    }

    public function store(StoreCountryRequest $request, AuditLogService $audit): RedirectResponse
    {
        $data = $request->validated();

        $country = Country::query()->create([
            'name' => $data['name'],
            'iso_code' => strtoupper($data['iso_code']),
            'is_active' => (bool) $data['is_active'],
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        $audit->record(
            eventType: 'settings.country_created',
            module: 'Settings',
            actionName: 'country_created',
            message: 'Country created.',
            entityType: $country::class,
            entityId: $country->id,
            afterState: $country->toArray(),
            actor: $request->user(),
        );

        return redirect('/admin/settings/countries')->with('success', 'Country created.');
    }

    public function edit(Request $request, Country $country): Response
    {
        return Inertia::render('Admin/Settings/Countries/Edit', [
            'country' => [
                'id' => $country->id,
                'name' => $country->name,
                'iso_code' => $country->iso_code,
                'is_active' => (bool) $country->is_active,
                'sort_order' => (int) ($country->sort_order ?? 0),
            ],
        ]);
    }

    public function update(UpdateCountryRequest $request, Country $country, AuditLogService $audit): RedirectResponse
    {
        $before = $country->toArray();
        $data = $request->validated();

        $country->forceFill([
            'name' => $data['name'],
            'iso_code' => strtoupper($data['iso_code']),
            'is_active' => (bool) $data['is_active'],
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ])->save();

        $audit->record(
            eventType: 'settings.country_updated',
            module: 'Settings',
            actionName: 'country_updated',
            message: 'Country updated.',
            entityType: $country::class,
            entityId: $country->id,
            beforeState: $before,
            afterState: $country->toArray(),
            actor: $request->user(),
        );

        return back()->with('success', 'Country updated.');
    }

    public function destroy(Request $request, Country $country, AuditLogService $audit): RedirectResponse
    {
        $before = $country->toArray();

        // Safer behavior: countries are almost always referenced; prefer deactivation.
        $country->forceFill(['is_active' => false])->save();

        $audit->record(
            eventType: 'settings.country_deactivated',
            module: 'Settings',
            actionName: 'country_deactivated',
            message: 'Country deactivated.',
            entityType: $country::class,
            entityId: $country->id,
            beforeState: $before,
            afterState: $country->toArray(),
            actor: $request->user(),
        );

        return back()->with('success', 'Country deactivated.');
    }
}

