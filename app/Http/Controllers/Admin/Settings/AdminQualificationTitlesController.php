<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Domain\Audit\AuditLogService;
use App\Domain\Settings\QualificationTitleExcelImportService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Settings\ImportQualificationTitlesExcelRequest;
use App\Http\Requests\Admin\Settings\StoreQualificationTitleRequest;
use App\Http\Requests\Admin\Settings\UpdateQualificationTitleRequest;
use App\Models\AwardingInstitution;
use App\Models\QualificationTitle;
use App\Models\QualificationType;
use App\Support\Imports\ExcelTemplateDownload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminQualificationTitlesController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $active = $request->query('active');
        $qualificationTypeId = $request->query('qualification_type_id');

        $titles = QualificationTitle::query()
            ->with('qualificationType:id,name,zqf_level_code')
            ->withCount('awardingInstitutions', 'qualifications')
            ->when($q !== '', function ($qq) use ($q) {
                $normalized = QualificationTitle::normalizeName($q);
                $qq->where(function ($inner) use ($q, $normalized) {
                    $inner->where('name', 'like', "%{$q}%");
                    if ($normalized !== '') {
                        $inner->orWhere('name_normalized', 'like', '%'.$normalized.'%');
                    }
                });
            })
            ->when($active === '1', fn ($qq) => $qq->where('is_active', true))
            ->when($active === '0', fn ($qq) => $qq->where('is_active', false))
            ->when(is_numeric($qualificationTypeId) && (int) $qualificationTypeId > 0, fn ($qq) => $qq->where('qualification_type_id', (int) $qualificationTypeId))
            ->ordered()
            ->paginate(25)
            ->withQueryString()
            ->through(fn (QualificationTitle $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'is_active' => (bool) $t->is_active,
                'sort_order' => (int) ($t->sort_order ?? 0),
                'qualification_type' => $t->qualificationType
                    ? ['id' => $t->qualificationType->id, 'name' => $t->qualificationType->name, 'zqf_level_code' => $t->qualificationType->zqf_level_code]
                    : null,
                'linked_institutions_count' => (int) ($t->awarding_institutions_count ?? 0),
                'usage_count' => (int) ($t->qualifications_count ?? 0),
            ]);

        $user = $request->user();

        return Inertia::render('Admin/Settings/QualificationTitles/Index', [
            'titles' => $titles,
            'filters' => [
                'q' => $q,
                'active' => is_string($active) ? $active : null,
                'qualification_type_id' => is_numeric($qualificationTypeId) ? (string) $qualificationTypeId : null,
            ],
            'qualificationTypes' => QualificationType::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'zqf_level_code']),
            'can' => [
                'create' => (bool) $user?->can('settings.qualification_titles.create'),
                'edit' => (bool) $user?->can('settings.qualification_titles.edit'),
                'delete' => (bool) $user?->can('settings.qualification_titles.delete'),
            ],
            'excel_import' => [
                'template_url' => route('admin.settings.qualification_titles.import_template'),
                'import_url' => route('admin.settings.qualification_titles.import'),
                'can_import' => (bool) ($user?->can('settings.qualification_titles.create') || $user?->can('settings.qualification_titles.edit')),
            ],
        ]);
    }

    public function importTemplate(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('settings.qualification_titles.view'), 403);

        return ExcelTemplateDownload::stream(
            'qualification-title-import-template.xlsx',
            ['title', 'qualification_type', 'is_active', 'sort_order', 'description'],
            [['Bachelor of Science in Information Technology', 'L6', 1, 0, 'Example description']],
        );
    }

    public function import(
        ImportQualificationTitlesExcelRequest $request,
        QualificationTitleExcelImportService $import,
    ): RedirectResponse {
        $report = $import->import($request->file('file'), $request->user());

        return back()
            ->with('success', $report->summaryLine())
            ->with('import_report', ['errors' => $report->errors]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Admin/Settings/QualificationTitles/Create', $this->formOptions());
    }

    public function store(StoreQualificationTitleRequest $request, AuditLogService $audit): RedirectResponse
    {
        $data = $request->validated();

        $title = QualificationTitle::query()->create([
            'name' => trim((string) $data['name']),
            'qualification_type_id' => $data['qualification_type_id'] ?? null,
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => $request->boolean('is_active', true),
        ]);

        $title->awardingInstitutions()->sync($data['awarding_institution_ids'] ?? []);

        $audit->record(
            eventType: 'settings.qualification_title_created',
            module: 'Settings',
            actionName: 'qualification_title_created',
            message: 'Qualification title created.',
            entityType: QualificationTitle::class,
            entityId: $title->id,
            afterState: $title->toArray(),
            actor: $request->user(),
        );

        return redirect()->route('admin.settings.qualification_titles.index')
            ->with('success', 'Qualification title created.');
    }

    public function edit(Request $request, QualificationTitle $qualification_title): Response
    {
        $qualification_title->load('awardingInstitutions:id');

        return Inertia::render('Admin/Settings/QualificationTitles/Edit', [
            ...$this->formOptions(),
            'title' => [
                'id' => $qualification_title->id,
                'name' => $qualification_title->name,
                'qualification_type_id' => $qualification_title->qualification_type_id,
                'description' => $qualification_title->description,
                'is_active' => (bool) $qualification_title->is_active,
                'sort_order' => (int) ($qualification_title->sort_order ?? 0),
                'awarding_institution_ids' => $qualification_title->awardingInstitutions->pluck('id')->map(fn ($id) => (int) $id)->all(),
                'usage_count' => $qualification_title->qualifications()->count(),
            ],
        ]);
    }

    public function update(UpdateQualificationTitleRequest $request, QualificationTitle $qualification_title, AuditLogService $audit): RedirectResponse
    {
        $before = $qualification_title->toArray();
        $data = $request->validated();

        $qualification_title->forceFill([
            'name' => trim((string) $data['name']),
            'qualification_type_id' => $data['qualification_type_id'] ?? null,
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => $request->boolean('is_active', true),
        ])->save();

        $qualification_title->awardingInstitutions()->sync($data['awarding_institution_ids'] ?? []);

        $audit->record(
            eventType: 'settings.qualification_title_updated',
            module: 'Settings',
            actionName: 'qualification_title_updated',
            message: 'Qualification title updated.',
            entityType: QualificationTitle::class,
            entityId: $qualification_title->id,
            beforeState: $before,
            afterState: $qualification_title->toArray(),
            actor: $request->user(),
        );

        return back()->with('success', 'Qualification title updated.');
    }

    public function destroy(Request $request, QualificationTitle $qualification_title, AuditLogService $audit): RedirectResponse
    {
        $before = $qualification_title->toArray();

        if ($qualification_title->qualifications()->exists()) {
            $qualification_title->forceFill(['is_active' => false])->save();
            $message = 'Qualification title deactivated (in use by applications).';
            $eventType = 'settings.qualification_title_deactivated';
        } else {
            $qualification_title->awardingInstitutions()->detach();
            $qualification_title->delete();
            $message = 'Qualification title deleted.';
            $eventType = 'settings.qualification_title_deleted';
        }

        $audit->record(
            eventType: $eventType,
            module: 'Settings',
            actionName: $eventType,
            message: $message,
            entityType: QualificationTitle::class,
            entityId: $qualification_title->id,
            beforeState: $before,
            afterState: $qualification_title->exists ? $qualification_title->toArray() : null,
            actor: $request->user(),
        );

        return back()->with('success', $message);
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'qualificationTypes' => QualificationType::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'zqf_level_code']),
            'awardingInstitutions' => AwardingInstitution::query()
                ->with('country:id,name')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'country_id'])
                ->map(fn (AwardingInstitution $i) => [
                    'id' => $i->id,
                    'name' => $i->name,
                    'country' => $i->country?->name,
                ]),
        ];
    }
}
