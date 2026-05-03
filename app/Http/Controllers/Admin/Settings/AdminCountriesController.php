<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Domain\Audit\AuditLogService;
use App\Domain\Settings\CountryExcelImportService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Settings\ImportCountriesExcelRequest;
use App\Http\Requests\Admin\Settings\StoreCountryRequest;
use App\Http\Requests\Admin\Settings\UpdateCountryRequest;
use App\Models\Country;
use App\Support\Imports\ExcelTemplateDownload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

        $user = $request->user();

        return Inertia::render('Admin/Settings/Countries/Index', [
            'countries' => $countries,
            'filters' => [
                'q' => $q,
                'active' => is_string($active) ? $active : null,
            ],
            'can' => [
                'create' => (bool) $user?->can('settings.countries.create'),
                'edit' => (bool) $user?->can('settings.countries.edit'),
                'delete' => (bool) $user?->can('settings.countries.delete'),
            ],
            'excel_import' => [
                'template_url' => route('admin.settings.countries.import_template'),
                'import_url' => route('admin.settings.countries.import'),
                'can_import' => (bool) ($user?->can('settings.countries.create') || $user?->can('settings.countries.edit')),
            ],
        ]);
    }

    public function importTemplate(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('settings.countries.view'), 403);

        return ExcelTemplateDownload::stream(
            'country-import-template.xlsx',
            ['name', 'iso_code', 'is_active', 'sort_order'],
            [['Example Country', 'ZZZ', 1, 0]],
        );
    }

    public function import(
        ImportCountriesExcelRequest $request,
        CountryExcelImportService $import,
    ): RedirectResponse {
        $report = $import->import($request->file('file'), $request->user());

        return back()
            ->with('success', $report->summaryLine())
            ->with('import_report', ['errors' => $report->errors]);
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
