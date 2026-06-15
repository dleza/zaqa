<?php

namespace App\Http\Controllers\Admin\LearnerRecords;

use App\Domain\LearnerRecords\LearnerRecordImportService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LearnerRecords\UploadLearnerRecordImportRequest;
use App\Models\AwardingInstitution;
use App\Models\LearnerRecordImport;
use App\Support\Imports\ExcelTemplateDownload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminLearnerRecordImportsController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('learner_records.view'), 403);

        $imports = LearnerRecordImport::query()
            ->with(['uploadedBy:id,name', 'awardingInstitution:id,name'])
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (LearnerRecordImport $i) => [
                'id' => $i->id,
                'original_filename' => $i->original_filename,
                'status' => $i->status?->value,
                'total_rows' => $i->total_rows,
                'processed_rows' => $i->processed_rows,
                'inserted_rows' => $i->inserted_rows,
                'updated_rows' => $i->updated_rows,
                'failed_rows' => $i->failed_rows,
                'started_at' => optional($i->started_at)->toIso8601String(),
                'completed_at' => optional($i->completed_at)->toIso8601String(),
                'created_at' => optional($i->created_at)->toIso8601String(),
                'uploaded_by' => $i->uploadedBy ? ['id' => $i->uploadedBy->id, 'name' => $i->uploadedBy->name] : null,
                'awarding_institution' => $i->awardingInstitution ? ['id' => $i->awardingInstitution->id, 'name' => $i->awardingInstitution->name] : null,
            ]);

        $institutions = AwardingInstitution::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (AwardingInstitution $i) => ['id' => $i->id, 'name' => $i->name])
            ->values();

        return Inertia::render('Admin/LearnerRecords/Imports/Index', [
            'imports' => $imports,
            'institutions' => $institutions,
            'can' => [
                'import' => (bool) $request->user()?->can('learner_records.import'),
            ],
            'template_url' => route('admin.learner_records.imports.template'),
        ]);
    }

    public function template(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('learner_records.view'), 403);

        return ExcelTemplateDownload::stream(
            'learner-records-import-template.xlsx',
            [
                'StudentID',
                'CertificateNo',
                'FirstName',
                'LastName',
                'OtherNames',
                'Gender',
                'NRCNumber',
                'PassportNo',
                'ProgramOfStudy',
                'YearAwarded',
                'Classification',
                'AwardDate',
            ],
            [
                [
                    'STU-001',
                    'CERT-001',
                    'Jane',
                    'Banda',
                    'Mary',
                    'Female',
                    '123456/78/1',
                    '',
                    'Bachelor of Science in Nursing',
                    2024,
                    'Merit',
                    '2024-11-15',
                ],
            ],
        );
    }

    public function store(
        UploadLearnerRecordImportRequest $request,
        LearnerRecordImportService $imports,
    ): RedirectResponse {
        $actor = $request->user();
        $import = $imports->createAndDispatch(
            file: $request->file('file'),
            actor: $actor,
            awardingInstitutionId: (int) $request->input('awarding_institution_id'),
        );

        return redirect()->route('admin.learner_records.imports.show', ['import' => $import->id])
            ->with('success', 'Learner records import queued. Processing will continue in the background.');
    }

    public function show(Request $request, LearnerRecordImport $import): Response
    {
        abort_unless($request->user()?->can('learner_records.view'), 403);

        $import->loadMissing(['uploadedBy:id,name', 'awardingInstitution:id,name']);

        return Inertia::render('Admin/LearnerRecords/Imports/Show', [
            'import' => [
                'id' => $import->id,
                'original_filename' => $import->original_filename,
                'status' => $import->status?->value,
                'total_rows' => $import->total_rows,
                'processed_rows' => $import->processed_rows,
                'inserted_rows' => $import->inserted_rows,
                'updated_rows' => $import->updated_rows,
                'failed_rows' => $import->failed_rows,
                'errors' => $import->errors,
                'started_at' => optional($import->started_at)->toIso8601String(),
                'completed_at' => optional($import->completed_at)->toIso8601String(),
                'created_at' => optional($import->created_at)->toIso8601String(),
                'uploaded_by' => $import->uploadedBy ? ['id' => $import->uploadedBy->id, 'name' => $import->uploadedBy->name] : null,
                'awarding_institution' => $import->awardingInstitution ? ['id' => $import->awardingInstitution->id, 'name' => $import->awardingInstitution->name] : null,
            ],
        ]);
    }
}

