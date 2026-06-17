<?php

namespace App\Http\Controllers\Admin\Reports;

use App\Domain\Reports\PaymentsRevenueReportService;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Support\Reports\ReportAuthorization;
use App\Support\Reports\ReportDateRange;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentsReportController extends Controller
{
    use HandlesReportExport;

    public function index(Request $request, PaymentsRevenueReportService $service): Response
    {
        $this->authorizePaymentsReportView();
        $dr = ReportDateRange::fromRequest($request);
        $invoiceStatus = $request->query('invoice_status') !== '' ? (string) $request->query('invoice_status') : null;

        $dashboard = $service->dashboard($dr['from'], $dr['to'], $invoiceStatus);

        $table = $service->paginateDetail($dr['from'], $dr['to'], $invoiceStatus)
            ->through(fn ($inv) => [
                'id' => $inv->id,
                'invoice_number' => $inv->invoice_number,
                'status' => $inv->status->value,
                'amount_cents' => $inv->amount_cents,
                'currency' => $inv->currency,
                'issued_at' => $inv->issued_at?->toIso8601String(),
                'paid_at' => $inv->paid_at?->toIso8601String(),
                'supplementary_of_invoice_id' => $inv->supplementary_of_invoice_id,
                'application' => $inv->application ? ['application_number' => $inv->application->application_number] : null,
            ]);

        return Inertia::render('Admin/Reports/Payments', [
            'filters' => [
                'range' => $dr['range'],
                'from' => $dr['from']->toDateString(),
                'to' => $dr['to']->toDateString(),
                'invoice_status' => $invoiceStatus ?? '',
            ],
            'dashboard' => $dashboard,
            'table' => $table,
            'invoice_status_options' => collect(InvoiceStatus::cases())->map(fn ($c) => [
                'value' => $c->value,
                'label' => str_replace('_', ' ', ucfirst($c->name)),
            ])->values()->all(),
        ]);
    }

    public function export(Request $request, PaymentsRevenueReportService $service): StreamedResponse|\Illuminate\Http\Response
    {
        $this->authorizePaymentsReportExport();
        $dr = ReportDateRange::fromRequest($request);
        $invoiceStatus = $request->query('invoice_status') !== '' ? (string) $request->query('invoice_status') : null;
        $format = strtolower((string) $request->query('format', 'csv'));

        $rows = $service->exportRows($dr['from'], $dr['to'], $invoiceStatus);
        $stub = 'payments-revenue-'.Carbon::now()->format('Y-m-d');

        if ($format === 'pdf') {
            $s = $service->dashboard($dr['from'], $dr['to'], $invoiceStatus)['summary'];
            $paidZmw = number_format(((int) $s['total_paid_amount_cents']) / 100, 2);
            $outZmw = number_format(((int) $s['outstanding_balance_cents']) / 100, 2);

            return $this->exportPdf('reports.pdf.applications-summary', [
                'title' => 'Payments & revenue',
                'period_from' => $dr['from']->toDateString(),
                'period_to' => $dr['to']->toDateString(),
                'generated_at' => now()->toDateTimeString(),
                'rows' => [
                    ['label' => 'Invoices generated (issued in period)', 'value' => $s['invoices_generated']],
                    ['label' => 'Paid invoices', 'value' => $s['paid_invoices']],
                    ['label' => 'Unpaid invoices (issued status)', 'value' => $s['unpaid_invoices']],
                    ['label' => 'Primary invoices', 'value' => $s['primary_invoices']],
                    ['label' => 'Supplementary invoices', 'value' => $s['supplementary_invoices']],
                    ['label' => 'Total paid amount (ZMW)', 'value' => $paidZmw],
                    ['label' => 'Outstanding balance (issued, ZMW)', 'value' => $outZmw],
                    ['label' => 'Payments awaiting finance review', 'value' => $s['payments_awaiting_finance_review']],
                ],
            ], $stub.'.pdf');
        }

        if ($format === 'xlsx') {
            return $this->exportXlsx($rows, $stub.'.xlsx');
        }

        return $this->exportCsv($rows, $stub.'.csv');
    }

    private function authorizePaymentsReportView(): void
    {
        ReportAuthorization::abortUnlessFinanceView(auth()->user());
    }

    private function authorizePaymentsReportExport(): void
    {
        ReportAuthorization::abortUnlessFinanceDownload(auth()->user());
    }
}
