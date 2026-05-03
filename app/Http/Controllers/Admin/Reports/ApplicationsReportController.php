<?php

namespace App\Http\Controllers\Admin\Reports;

use App\Domain\Reports\ApplicationsReportService;
use App\Enums\ApplicantType;
use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Support\Reports\ReportDateRange;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ApplicationsReportController extends Controller
{
    use HandlesReportExport;

    public function index(Request $request, ApplicationsReportService $service): Response
    {
        $dr = ReportDateRange::fromRequest($request);
        $status = $request->query('status') !== '' ? (string) $request->query('status') : null;
        $applicantType = $request->query('applicant_type') !== '' ? (string) $request->query('applicant_type') : null;

        $dashboard = $service->dashboard($dr['from'], $dr['to'], $status, $applicantType);

        $table = $service->paginateDetail($dr['from'], $dr['to'], $status, $applicantType)
            ->through(fn ($a) => [
                'id' => $a->id,
                'application_number' => $a->application_number,
                'applicant_type' => $a->applicant_type?->value,
                'current_status' => $a->current_status?->value,
                'submitted_at' => $a->submitted_at?->toIso8601String(),
                'created_at' => $a->created_at?->toIso8601String(),
                'applicant' => $a->applicant ? ['name' => $a->applicant->name] : null,
            ]);

        return Inertia::render('Admin/Reports/Applications', [
            'filters' => [
                'range' => $dr['range'],
                'from' => $dr['from']->toDateString(),
                'to' => $dr['to']->toDateString(),
                'status' => $status ?? '',
                'applicant_type' => $applicantType ?? '',
            ],
            'dashboard' => $dashboard,
            'table' => $table,
            'status_options' => collect(ApplicationStatus::cases())->map(fn ($c) => [
                'value' => $c->value,
                'label' => str_replace('_', ' ', ucfirst($c->name)),
            ])->values()->all(),
            'applicant_type_options' => collect(ApplicantType::cases())->map(fn ($c) => [
                'value' => $c->value,
                'label' => ucfirst($c->value),
            ])->values()->all(),
        ]);
    }

    public function export(Request $request, ApplicationsReportService $service): StreamedResponse|\Illuminate\Http\Response
    {
        $dr = ReportDateRange::fromRequest($request);
        $status = $request->query('status') !== '' ? (string) $request->query('status') : null;
        $applicantType = $request->query('applicant_type') !== '' ? (string) $request->query('applicant_type') : null;
        $format = strtolower((string) $request->query('format', 'csv'));

        $rows = $service->exportRows($dr['from'], $dr['to'], $status, $applicantType);
        $stub = 'applications-overview-'.Carbon::now()->format('Y-m-d');

        if ($format === 'pdf') {
            $dash = $service->dashboard($dr['from'], $dr['to'], $status, $applicantType);
            $summary = $dash['summary'];

            return $this->exportPdf('reports.pdf.applications-summary', [
                'title' => 'Applications overview',
                'period_from' => $dr['from']->toDateString(),
                'period_to' => $dr['to']->toDateString(),
                'generated_at' => now()->toDateTimeString(),
                'rows' => [
                    ['label' => 'Total applications', 'value' => $summary['total']],
                    ['label' => 'Draft', 'value' => $summary['draft']],
                    ['label' => 'Submitted / resubmitted', 'value' => $summary['submitted']],
                    ['label' => 'Sent back', 'value' => $summary['sent_back']],
                    ['label' => 'Approved / completed', 'value' => $summary['approved_or_completed']],
                    ['label' => 'Rejected', 'value' => $summary['rejected']],
                    ['label' => 'In progress / pending payment', 'value' => $summary['in_progress']],
                ],
            ], $stub.'.pdf');
        }

        if ($format === 'xlsx') {
            return $this->exportXlsx($rows, $stub.'.xlsx');
        }

        return $this->exportCsv($rows, $stub.'.csv');
    }
}
