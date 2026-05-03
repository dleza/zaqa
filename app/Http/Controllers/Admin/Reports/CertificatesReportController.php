<?php

namespace App\Http\Controllers\Admin\Reports;

use App\Domain\Reports\CertificatesIssuedReportService;
use App\Http\Controllers\Controller;
use App\Models\AwardingInstitution;
use App\Models\QualificationType;
use App\Support\Reports\ReportDateRange;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CertificatesReportController extends Controller
{
    use HandlesReportExport;

    public function index(Request $request, CertificatesIssuedReportService $service): Response
    {
        $dr = ReportDateRange::fromRequest($request);
        $qtId = $request->query('qualification_type_id') ? (int) $request->query('qualification_type_id') : null;
        $aiId = $request->query('awarding_institution_id') ? (int) $request->query('awarding_institution_id') : null;

        $dashboard = $service->dashboard($dr['from'], $dr['to'], $qtId, $aiId);

        $table = $service->paginateDetail($dr['from'], $dr['to'], $qtId, $aiId)
            ->through(fn ($c) => [
                'id' => $c->id,
                'certificate_number' => $c->certificate_number,
                'issued_at' => $c->issued_at?->toIso8601String(),
                'status' => $c->status,
                'qualification' => $c->qualification ? [
                    'title' => $c->qualification->title_of_qualification,
                    'type' => $c->qualification->qualificationTypeMaster?->name ?? $c->qualification->qualification_type,
                    'institution' => $c->qualification->awardingInstitution?->name ?? $c->qualification->awarding_institution_name,
                ] : null,
            ]);

        return Inertia::render('Admin/Reports/Certificates', [
            'filters' => [
                'range' => $dr['range'],
                'from' => $dr['from']->toDateString(),
                'to' => $dr['to']->toDateString(),
                'qualification_type_id' => $qtId,
                'awarding_institution_id' => $aiId,
            ],
            'dashboard' => $dashboard,
            'table' => $table,
            'qualification_type_options' => QualificationType::query()->orderBy('name')->get(['id', 'name']),
            'institution_options' => AwardingInstitution::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function export(Request $request, CertificatesIssuedReportService $service): StreamedResponse|\Illuminate\Http\Response
    {
        $dr = ReportDateRange::fromRequest($request);
        $qtId = $request->query('qualification_type_id') ? (int) $request->query('qualification_type_id') : null;
        $aiId = $request->query('awarding_institution_id') ? (int) $request->query('awarding_institution_id') : null;
        $format = strtolower((string) $request->query('format', 'csv'));

        $rows = $service->exportRows($dr['from'], $dr['to'], $qtId, $aiId);
        $stub = 'certificates-issued-'.Carbon::now()->format('Y-m-d');

        if ($format === 'pdf') {
            $s = $service->dashboard($dr['from'], $dr['to'], $qtId, $aiId)['summary'];

            return $this->exportPdf('reports.pdf.applications-summary', [
                'title' => 'Certificates issued',
                'period_from' => $dr['from']->toDateString(),
                'period_to' => $dr['to']->toDateString(),
                'generated_at' => now()->toDateTimeString(),
                'rows' => [
                    ['label' => 'Total certificates', 'value' => $s['total']],
                    ['label' => 'Issued', 'value' => $s['issued']],
                    ['label' => 'Reissued', 'value' => $s['reissued']],
                    ['label' => 'Revoked', 'value' => $s['revoked']],
                ],
            ], $stub.'.pdf');
        }

        if ($format === 'xlsx') {
            return $this->exportXlsx($rows, $stub.'.xlsx');
        }

        return $this->exportCsv($rows, $stub.'.csv');
    }
}
