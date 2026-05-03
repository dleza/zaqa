<?php

namespace App\Domain\Reports;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Reports\SqlDialect;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final class PaymentsRevenueReportService
{
    /**
     * @return array{
     *   summary: array<string, int|float>,
     *   revenue_by_month: array{labels: array<int, string>, values: array<int, float>},
     *   paid_vs_unpaid: array{labels: array<int, string>, values: array<int, int>},
     *   primary_vs_supplementary: array{labels: array<int, string>, values: array<int, int>}
     * }
     */
    public function dashboard(Carbon $from, Carbon $to, ?string $invoiceStatus): array
    {
        return [
            'summary' => $this->summary($from, $to, $invoiceStatus),
            'revenue_by_month' => $this->revenueByMonth($from, $to),
            'paid_vs_unpaid' => $this->paidVsUnpaid($from, $to, $invoiceStatus),
            'primary_vs_supplementary' => $this->primaryVsSupplementary($from, $to, $invoiceStatus),
        ];
    }

    private function invoiceBase(Carbon $from, Carbon $to, ?string $invoiceStatus): Builder
    {
        $q = Invoice::query()
            ->whereNotNull('issued_at')
            ->whereBetween('issued_at', [$from, $to]);

        if ($invoiceStatus !== null && $invoiceStatus !== '') {
            $q->where('status', $invoiceStatus);
        }

        return $q;
    }

    /**
     * @return array<string, int|float>
     */
    private function summary(Carbon $from, Carbon $to, ?string $invoiceStatus): array
    {
        $base = $this->invoiceBase($from, $to, $invoiceStatus);

        $invoicesGenerated = (clone $base)->count();
        $paid = (clone $base)->where('status', InvoiceStatus::Paid)->count();
        $unpaid = (clone $base)->whereIn('status', [InvoiceStatus::Issued, InvoiceStatus::Draft])->count();

        $supplementary = (clone $base)->whereNotNull('supplementary_of_invoice_id')->count();
        $primary = (clone $base)->whereNull('supplementary_of_invoice_id')->count();

        $totalPaidCents = (clone $base)->where('status', InvoiceStatus::Paid)->sum('amount_cents');

        $outstandingCents = (clone $base)
            ->where('status', InvoiceStatus::Issued)
            ->sum('amount_cents');

        $financeReviewPayments = Payment::query()
            ->where('status', PaymentStatus::AwaitingFinanceReview)
            ->whereBetween('updated_at', [$from, $to])
            ->count();

        return [
            'invoices_generated' => $invoicesGenerated,
            'paid_invoices' => $paid,
            'unpaid_invoices' => $unpaid,
            'primary_invoices' => $primary,
            'supplementary_invoices' => $supplementary,
            'total_paid_amount_cents' => (int) $totalPaidCents,
            'outstanding_balance_cents' => (int) $outstandingCents,
            'payments_awaiting_finance_review' => $financeReviewPayments,
        ];
    }

    /**
     * Paid revenue by calendar month (paid_at).
     *
     * @return array{labels: array<int, string>, values: array<int, float>}
     */
    private function revenueByMonth(Carbon $from, Carbon $to): array
    {
        $month = SqlDialect::monthBucket('paid_at');
        $rows = Invoice::query()
            ->where('status', InvoiceStatus::Paid)
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$from, $to])
            ->selectRaw("{$month} as ym, sum(amount_cents) as total")
            ->groupBy('ym')
            ->orderBy('ym')
            ->get();

        $labels = [];
        $values = [];
        foreach ($rows as $r) {
            $labels[] = (string) $r->ym;
            $values[] = round(((int) $r->total) / 100, 2);
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * @return array{labels: array<int, string>, values: array<int, int>}
     */
    private function paidVsUnpaid(Carbon $from, Carbon $to, ?string $invoiceStatus): array
    {
        $base = $this->invoiceBase($from, $to, $invoiceStatus);
        $paid = (clone $base)->where('status', InvoiceStatus::Paid)->count();
        $unpaid = (clone $base)->where('status', InvoiceStatus::Issued)->count();

        return [
            'labels' => ['Paid', 'Unpaid (issued)'],
            'values' => [$paid, $unpaid],
        ];
    }

    /**
     * @return array{labels: array<int, string>, values: array<int, int>}
     */
    private function primaryVsSupplementary(Carbon $from, Carbon $to, ?string $invoiceStatus): array
    {
        $base = $this->invoiceBase($from, $to, $invoiceStatus);
        $primary = (clone $base)->whereNull('supplementary_of_invoice_id')->count();
        $sup = (clone $base)->whereNotNull('supplementary_of_invoice_id')->count();

        return [
            'labels' => ['Primary', 'Supplementary'],
            'values' => [$primary, $sup],
        ];
    }

    /**
     * @return LengthAwarePaginator<int, Invoice>
     */
    public function paginateDetail(Carbon $from, Carbon $to, ?string $invoiceStatus, int $perPage = 25): LengthAwarePaginator
    {
        return $this->invoiceBase($from, $to, $invoiceStatus)
            ->with(['application:id,application_number'])
            ->orderByDesc('issued_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @return \Generator<int, list<string|int|null>>
     */
    public function exportRows(Carbon $from, Carbon $to, ?string $invoiceStatus): \Generator
    {
        yield ['id', 'invoice_number', 'application_id', 'status', 'amount_cents', 'currency', 'issued_at', 'paid_at', 'supplementary_of_invoice_id'];

        $q = $this->invoiceBase($from, $to, $invoiceStatus)->orderBy('id');

        foreach ($q->cursor() as $inv) {
            /** @var Invoice $inv */
            yield [
                $inv->id,
                $inv->invoice_number,
                $inv->application_id,
                $inv->status->value,
                $inv->amount_cents,
                $inv->currency,
                $inv->issued_at?->toIso8601String(),
                $inv->paid_at?->toIso8601String(),
                $inv->supplementary_of_invoice_id,
            ];
        }
    }
}
