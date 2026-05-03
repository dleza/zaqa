<?php

namespace App\Http\Controllers\Admin\Reports;

use App\Domain\Reports\AwardingInstitutionsReportService;
use App\Http\Controllers\Controller;
use App\Support\Reports\ReportDateRange;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AwardingInstitutionsReportController extends Controller
{
    use HandlesReportExport;

    public function index(Request $request, AwardingInstitutionsReportService $service): Response
    {
        $dr = ReportDateRange::fromRequest($request);
        $institutionId = $request->query('awarding_institution_id') ? (int) $request->query('awarding_institution_id') : null;
        $foreignOnly = $request->query('foreign_qualification');
        $foreignBool = $foreignOnly === null || $foreignOnly === '' ? null : $foreignOnly === '1';

        $dashboard = $service->dashboard($dr['from'], $dr['to'], $institutionId, $foreignBool);

        $table = $service->paginateDetail($dr['from'], $dr['to'], $institutionId, $foreignBool)
            ->through(fn ($q) => [
                'id' => $q->id,
                'awarding_institution_name' => $q->awarding_institution_name,
                'verification_state' => $q->verification_state?->value,
                'is_foreign_qualification' => $q->is_foreign_qualification,
                'created_at' => $q->created_at?->toIso8601String(),
                'institution' => $q->awardingInstitution ? ['name' => $q->awardingInstitution->name] : null,
                'application' => $q->application ? ['application_number' => $q->application->application_number] : null,
            ]);

        return Inertia::render('Admin/Reports/AwardingInstitutions', [
            'filters' => [
                'range' => $dr['range'],
                'from' => $dr['from']->toDateString(),
                'to' => $dr['to']->toDateString(),
                'awarding_institution_id' => $institutionId,
                'foreign_qualification' => $foreignOnly === null || $foreignOnly === '' ? '' : (string) $foreignOnly,
            ],
            'dashboard' => $dashboard,
            'table' => $table,
            'institution_options' => $service->institutionOptions(),
        ]);
    }

    public function export(Request $request, AwardingInstitutionsReportService $service): StreamedResponse|\Illuminate\Http\Response
    {
        $dr = ReportDateRange::fromRequest($request);
        $institutionId = $request->query('awarding_institution_id') ? (int) $request->query('awarding_institution_id') : null;
        $foreignOnly = $request->query('foreign_qualification');
        $foreignBool = $foreignOnly === null || $foreignOnly === '' ? null : $foreignOnly === '1';
        $format = strtolower((string) $request->query('format', 'csv'));

        $rows = $service->exportRows($dr['from'], $dr['to'], $institutionId, $foreignBool);
        $stub = 'awarding-institutions-'.Carbon::now()->format('Y-m-d');

        if ($format === 'pdf') {
            $s = $service->dashboard($dr['from'], $dr['to'], $institutionId, $foreignBool);

            return $this->exportPdf('reports.pdf.applications-summary', [
                'title' => 'Awarding institutions',
                'period_from' => $dr['from']->toDateString(),
                'period_to' => $dr['to']->toDateString(),
                'generated_at' => now()->toDateTimeString(),
                'rows' => [
                    ['label' => 'Qualifications total', 'value' => $s['summary']['qualifications_total']],
                    ['label' => 'With institution link', 'value' => $s['summary']['with_institution_id']],
                    ['label' => 'Local qualifications', 'value' => $s['summary']['local_qualifications']],
                    ['label' => 'Foreign qualifications', 'value' => $s['summary']['foreign_qualifications']],
                    ['label' => 'Institutions missing consent (distinct)', 'value' => $s['institutions_missing_consent']],
                ],
            ], $stub.'.pdf');
        }

        if ($format === 'xlsx') {
            return $this->exportXlsx($rows, $stub.'.xlsx');
        }

        return $this->exportCsv($rows, $stub.'.csv');
    }
}
