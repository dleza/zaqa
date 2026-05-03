<?php

namespace App\Http\Controllers\Admin\Reports;

use App\Domain\Reports\QualificationVerificationReportService;
use App\Enums\VerificationState;
use App\Http\Controllers\Controller;
use App\Support\Reports\ReportDateRange;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class QualificationsReportController extends Controller
{
    use HandlesReportExport;

    public function index(Request $request, QualificationVerificationReportService $service): Response
    {
        $dr = ReportDateRange::fromRequest($request);
        $verificationState = $request->query('verification_state') !== '' ? (string) $request->query('verification_state') : null;
        $qualificationTypeId = $request->query('qualification_type_id') ? (int) $request->query('qualification_type_id') : null;

        $dashboard = $service->dashboard($dr['from'], $dr['to'], $verificationState, $qualificationTypeId);

        $table = $service->paginateDetail($dr['from'], $dr['to'], $verificationState, $qualificationTypeId)
            ->through(fn ($q) => [
                'id' => $q->id,
                'verification_reference_number' => $q->verification_reference_number,
                'qualification_type' => $q->qualification_type,
                'verification_state' => $q->verification_state?->value,
                'is_foreign_qualification' => $q->is_foreign_qualification,
                'created_at' => $q->created_at?->toIso8601String(),
                'application' => $q->application ? ['application_number' => $q->application->application_number] : null,
                'qualification_type_label' => $q->qualificationTypeMaster?->name,
            ]);

        return Inertia::render('Admin/Reports/Qualifications', [
            'filters' => [
                'range' => $dr['range'],
                'from' => $dr['from']->toDateString(),
                'to' => $dr['to']->toDateString(),
                'verification_state' => $verificationState ?? '',
                'qualification_type_id' => $qualificationTypeId,
            ],
            'dashboard' => $dashboard,
            'table' => $table,
            'qualification_type_options' => $service->qualificationTypeOptions(),
            'verification_state_options' => collect(VerificationState::cases())->map(fn ($c) => [
                'value' => $c->value,
                'label' => str_replace('_', ' ', ucfirst($c->name)),
            ])->values()->all(),
        ]);
    }

    public function export(Request $request, QualificationVerificationReportService $service): StreamedResponse|\Illuminate\Http\Response
    {
        $dr = ReportDateRange::fromRequest($request);
        $verificationState = $request->query('verification_state') !== '' ? (string) $request->query('verification_state') : null;
        $qualificationTypeId = $request->query('qualification_type_id') ? (int) $request->query('qualification_type_id') : null;
        $format = strtolower((string) $request->query('format', 'csv'));

        $rows = $service->exportRows($dr['from'], $dr['to'], $verificationState, $qualificationTypeId);
        $stub = 'qualification-verification-'.Carbon::now()->format('Y-m-d');

        if ($format === 'pdf') {
            $s = $service->dashboard($dr['from'], $dr['to'], $verificationState, $qualificationTypeId)['summary'];

            return $this->exportPdf('reports.pdf.applications-summary', [
                'title' => 'Qualification verification',
                'period_from' => $dr['from']->toDateString(),
                'period_to' => $dr['to']->toDateString(),
                'generated_at' => now()->toDateTimeString(),
                'rows' => [
                    ['label' => 'Total qualifications', 'value' => $s['total']],
                    ['label' => 'Returned for amendment', 'value' => $s['returned_for_amendment']],
                    ['label' => 'Approved (incl. certificate issued)', 'value' => $s['approved']],
                    ['label' => 'Rejected', 'value' => $s['rejected']],
                    ['label' => 'Local qualifications', 'value' => $s['local']],
                    ['label' => 'Foreign qualifications', 'value' => $s['foreign']],
                ],
            ], $stub.'.pdf');
        }

        if ($format === 'xlsx') {
            return $this->exportXlsx($rows, $stub.'.xlsx');
        }

        return $this->exportCsv($rows, $stub.'.csv');
    }
}
