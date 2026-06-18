<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Domain\Audit\AuditLogService;
use App\Domain\InstitutionIntegrations\InstitutionPullLookupPreviewService;
use App\Domain\Settings\AwardingInstitutionAccreditationStatementService;
use App\Domain\Settings\AwardingInstitutionExcelImportService;
use App\Domain\Settings\AwardingInstitutionProfileService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Settings\ImportAwardingInstitutionsExcelRequest;
use App\Http\Requests\Admin\Settings\PreviewInstitutionPullLookupRequest;
use App\Http\Requests\Admin\Settings\StoreAwardingInstitutionRequest;
use App\Http\Requests\Admin\Settings\UpdateAwardingInstitutionRequest;
use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Support\Imports\ExcelTemplateDownload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminAwardingInstitutionsController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $countryId = $request->query('country_id');
        $active = $request->query('active');
        $missingStatement = $request->query('missing_statement');

        $institutions = AwardingInstitution::query()
            ->with('country')
            ->when($q !== '', fn ($qq) => $qq->where('name', 'like', "%{$q}%"))
            ->when(is_string($countryId) && $countryId !== '', fn ($qq) => $qq->where('country_id', (int) $countryId))
            ->when($active === '1', fn ($qq) => $qq->where('is_active', true))
            ->when($active === '0', fn ($qq) => $qq->where('is_active', false))
            ->when($missingStatement === '1', fn ($qq) => $qq->where(function ($query) {
                $query->whereNull('accreditation_statement')
                    ->orWhere('accreditation_statement', '');
            }))
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
                'has_accreditation_statement' => trim((string) ($i->accreditation_statement ?? '')) !== '',
                'show_url' => route('admin.settings.awarding_institutions.show', ['awardingInstitution' => $i->id]),
            ]);

        $countries = Country::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'iso_code'])
            ->map(fn (Country $c) => ['id' => $c->id, 'name' => $c->name, 'iso_code' => $c->iso_code])
            ->values();

        $user = $request->user();

        return Inertia::render('Admin/Settings/AwardingInstitutions/Index', [
            'institutions' => $institutions,
            'countries' => $countries,
            'filters' => [
                'q' => $q,
                'country_id' => is_string($countryId) ? $countryId : null,
                'active' => is_string($active) ? $active : null,
                'missing_statement' => is_string($missingStatement) ? $missingStatement : null,
            ],
            'can' => [
                'create' => (bool) $user?->can('settings.awarding_institutions.create'),
                'edit' => (bool) $user?->can('settings.awarding_institutions.edit'),
                'delete' => (bool) $user?->can('settings.awarding_institutions.delete'),
            ],
            'excel_import' => [
                'template_url' => route('admin.settings.awarding_institutions.import_template'),
                'import_url' => route('admin.settings.awarding_institutions.import'),
                'can_import' => (bool) ($user?->can('settings.awarding_institutions.create') || $user?->can('settings.awarding_institutions.edit')),
            ],
        ]);
    }

    public function importTemplate(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('settings.awarding_institutions.view'), 403);

        return ExcelTemplateDownload::stream(
            'awarding-institution-import-template.xlsx',
            ['name', 'country_iso_code', 'is_active', 'sort_order'],
            [['Example Institution', 'ZZZ', 1, 0]],
        );
    }

    public function import(
        ImportAwardingInstitutionsExcelRequest $request,
        AwardingInstitutionExcelImportService $import,
    ): RedirectResponse {
        $report = $import->import($request->file('file'), $request->user());

        return back()
            ->with('success', $report->summaryLine())
            ->with('import_report', ['errors' => $report->errors]);
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

    public function store(
        StoreAwardingInstitutionRequest $request,
        AuditLogService $audit,
        AwardingInstitutionAccreditationStatementService $accreditationStatements,
    ): RedirectResponse {
        $data = $request->validated();

        $institution = DB::transaction(function () use ($data, $request) {
            $inst = AwardingInstitution::query()->create([
                'country_id' => (int) $data['country_id'],
                'name' => $data['name'],
                'is_active' => (bool) $data['is_active'],
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'accreditation_statement' => $data['accreditation_statement'] ?? null,
                'accreditation_statement_source' => ($data['accreditation_statement'] ?? null) !== null
                    ? AwardingInstitutionAccreditationStatementService::SOURCE_MANUAL
                    : null,
                'accreditation_statement_updated_by_user_id' => ($data['accreditation_statement'] ?? null) !== null
                    ? $request->user()?->id
                    : null,
                'accreditation_statement_updated_at' => ($data['accreditation_statement'] ?? null) !== null ? now() : null,
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

        if (($data['accreditation_statement'] ?? null) !== null && $request->user()) {
            $accreditationStatements->recordAdminUpdate(
                $institution,
                $request->user(),
                null,
                $data['accreditation_statement'],
            );
        }

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
        $awardingInstitution->loadMissing('accreditationStatementUpdatedBy');

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
                'accreditation_statement' => $awardingInstitution->accreditation_statement,
                'accreditation_statement_source' => $awardingInstitution->accreditation_statement_source,
                'accreditation_statement_updated_at' => optional($awardingInstitution->accreditation_statement_updated_at)?->toIso8601String(),
                'accreditation_statement_updated_by_name' => $awardingInstitution->accreditationStatementUpdatedBy?->name,
            ],
            'countries' => $countries,
        ]);
    }

    public function show(Request $request, AwardingInstitution $awardingInstitution, AwardingInstitutionProfileService $profile): Response
    {
        abort_unless($request->user()?->can('settings.awarding_institutions.view'), 403);

        $data = $profile->build($awardingInstitution);

        $user = $request->user();

        return Inertia::render('Admin/Settings/AwardingInstitutions/Show', [
            ...$data,
            'can' => [
                'edit' => (bool) $user?->can('settings.awarding_institutions.edit'),
                'deactivate' => (bool) $user?->can('settings.awarding_institutions.delete'),
                'view_learner_records' => (bool) $user?->can('learner_records.view'),
                'manage_integrations' => (bool) $user?->can('institution_api.manage'),
                'view_integration_logs' => (bool) $user?->can('institution_api.logs.view'),
                'view_qualifications_pool' => (bool) $user?->can('verification.pool.view'),
                'view_auto_verified' => (bool) $user?->can('verification.level2.review'),
            ],
        ]);
    }

    public function previewPullLookup(
        PreviewInstitutionPullLookupRequest $request,
        AwardingInstitution $awardingInstitution,
        InstitutionPullLookupPreviewService $preview,
        AuditLogService $audit,
    ): JsonResponse {
        $result = $preview->preview($awardingInstitution, $request->validated());

        if (($result['ok'] ?? false) === true) {
            $audit->record(
                eventType: 'institution_integration.pull_lookup_preview',
                module: 'Settings',
                actionName: 'institution_pull_lookup_preview',
                message: 'Institution pull lookup preview executed.',
                entityType: AwardingInstitution::class,
                entityId: (int) $awardingInstitution->id,
                metadata: [
                    'awarding_institution_id' => (int) $awardingInstitution->id,
                    'found' => (bool) ($result['found'] ?? false),
                    'status' => (string) ($result['status'] ?? ''),
                    'http_status' => $result['http_status'] ?? null,
                    'latency_ms' => $result['latency_ms'] ?? null,
                    'source_reference' => $result['source_reference'] ?? null,
                ],
                actor: $request->user(),
            );
        }

        return response()->json($result, ($result['ok'] ?? false) === true ? 200 : 422);
    }

    public function update(
        UpdateAwardingInstitutionRequest $request,
        AwardingInstitution $awardingInstitution,
        AuditLogService $audit,
        AwardingInstitutionAccreditationStatementService $accreditationStatements,
    ): RedirectResponse {
        $before = $awardingInstitution->toArray();
        $data = $request->validated();
        $beforeStatement = $awardingInstitution->accreditation_statement;

        DB::transaction(function () use ($request, $data, $awardingInstitution) {
            $statement = $data['accreditation_statement'] ?? null;
            $statementChanged = array_key_exists('accreditation_statement', $data)
                && $statement !== $awardingInstitution->accreditation_statement;

            $awardingInstitution->forceFill([
                'country_id' => (int) $data['country_id'],
                'name' => $data['name'],
                'is_active' => (bool) $data['is_active'],
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'accreditation_statement' => $statement,
                'accreditation_statement_source' => $statementChanged && $statement !== null
                    ? AwardingInstitutionAccreditationStatementService::SOURCE_MANUAL
                    : $awardingInstitution->accreditation_statement_source,
                'accreditation_statement_updated_by_user_id' => $statementChanged
                    ? $request->user()?->id
                    : $awardingInstitution->accreditation_statement_updated_by_user_id,
                'accreditation_statement_updated_at' => $statementChanged ? now() : $awardingInstitution->accreditation_statement_updated_at,
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

        if (array_key_exists('accreditation_statement', $data)
            && $data['accreditation_statement'] !== $beforeStatement
            && $request->user()) {
            $accreditationStatements->recordAdminUpdate(
                $awardingInstitution->fresh(),
                $request->user(),
                $beforeStatement,
                $data['accreditation_statement'],
            );
        }

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

    public function deactivate(Request $request, AwardingInstitution $awardingInstitution, AuditLogService $audit): RedirectResponse
    {
        abort_unless($request->user()?->can('settings.awarding_institutions.delete'), 403);

        $before = $awardingInstitution->toArray();

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

    public function reactivate(Request $request, AwardingInstitution $awardingInstitution, AuditLogService $audit): RedirectResponse
    {
        abort_unless($request->user()?->can('settings.awarding_institutions.delete'), 403);

        $before = $awardingInstitution->toArray();

        $awardingInstitution->forceFill(['is_active' => true])->save();

        $audit->record(
            eventType: 'settings.awarding_institution_reactivated',
            module: 'Settings',
            actionName: 'awarding_institution_reactivated',
            message: 'Awarding institution reactivated.',
            entityType: $awardingInstitution::class,
            entityId: $awardingInstitution->id,
            beforeState: $before,
            afterState: $awardingInstitution->toArray(),
            actor: $request->user(),
        );

        return back()->with('success', 'Awarding institution reactivated.');
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
