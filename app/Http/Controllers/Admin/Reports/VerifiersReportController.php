<?php

namespace App\Http\Controllers\Admin\Reports;

use App\Domain\Reports\VerifierPerformanceReportService;
use App\Http\Controllers\Controller;
use App\Support\Reports\ReportDateRange;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VerifiersReportController extends Controller
{
    use AuthorizesAdminReports;
    use HandlesReportExport;

    public function index(Request $request, VerifierPerformanceReportService $service): Response
    {
        $this->authorizeVerificationReportView();
        $dr = ReportDateRange::fromRequest($request);
        $verifierId = $request->query('verifier_id') ? (int) $request->query('verifier_id') : null;

        $dashboard = $service->dashboard($dr['from'], $dr['to'], $verifierId);

        return Inertia::render('Admin/Reports/Verifiers', [
            'filters' => [
                'range' => $dr['range'],
                'from' => $dr['from']->toDateString(),
                'to' => $dr['to']->toDateString(),
                'verifier_id' => $verifierId,
            ],
            'dashboard' => $dashboard,
            'verifier_options' => $service->verifierOptions(),
        ]);
    }

    public function export(Request $request, VerifierPerformanceReportService $service): StreamedResponse|\Illuminate\Http\Response
    {
        $this->authorizeVerificationReportDownload();
        $dr = ReportDateRange::fromRequest($request);
        $verifierId = $request->query('verifier_id') ? (int) $request->query('verifier_id') : null;
        $format = strtolower((string) $request->query('format', 'csv'));

        $rows = $service->exportRows($dr['from'], $dr['to'], $verifierId);
        $stub = 'verifier-performance-'.Carbon::now()->format('Y-m-d');

        if ($format === 'pdf') {
            $dash = $service->dashboard($dr['from'], $dr['to'], $verifierId);
            $pdfRows = [];
            foreach ($dash['rows'] as $r) {
                $pdfRows[] = [
                    'label' => $r['name'].' (assignments)',
                    'value' => $r['assignments'],
                ];
            }

            return $this->exportPdf('reports.pdf.applications-summary', [
                'title' => 'Verifier performance',
                'period_from' => $dr['from']->toDateString(),
                'period_to' => $dr['to']->toDateString(),
                'generated_at' => now()->toDateTimeString(),
                'rows' => array_slice($pdfRows, 0, 20),
            ], $stub.'.pdf');
        }

        if ($format === 'xlsx') {
            return $this->exportXlsx($rows, $stub.'.xlsx');
        }

        return $this->exportCsv($rows, $stub.'.csv');
    }
}
