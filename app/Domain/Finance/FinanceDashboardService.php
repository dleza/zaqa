<?php

namespace App\Domain\Finance;

use App\Domain\AdminDashboard\DashboardDateRange;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Support\Carbon;

class FinanceDashboardService
{
    /**
     * @return array<string,mixed>
     */
    public function build(DashboardDateRange $range): array
    {
        $now = Carbon::now();
        $from = $range->from;
        $to = $range->to;
        $rangeLabel = $range->label();

        $pendingProofs = Payment::query()
            ->where('status', PaymentStatus::AwaitingFinanceReview)
            ->whereIn('method', [PaymentMethod::BankDeposit, PaymentMethod::BankTransfer])
            ->count();

        $confirmedPeriod = Payment::query()
            ->where('status', PaymentStatus::Confirmed)
            ->whereBetween('confirmed_at', [$from, $to])
            ->count();

        $rejectedPeriod = Payment::query()
            ->where('status', PaymentStatus::Rejected)
            ->whereBetween('reviewed_at', [$from, $to])
            ->count();

        $failedPeriod = Payment::query()
            ->where('status', PaymentStatus::Failed)
            ->whereBetween('failed_at', [$from, $to])
            ->count();

        $revenuePeriod = (int) Payment::query()
            ->where('status', PaymentStatus::Confirmed)
            ->whereBetween('confirmed_at', [$from, $to])
            ->sum('amount_cents');

        $byMethod = [];
        foreach (PaymentMethod::cases() as $m) {
            $byMethod[] = [
                'method' => $m->value,
                'count' => (int) Payment::query()
                    ->where('status', PaymentStatus::Confirmed)
                    ->whereBetween('confirmed_at', [$from, $to])
                    ->where('method', $m)
                    ->count(),
            ];
        }

        $labels = [];
        $values = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();
        while ($cursor->lessThanOrEqualTo($end)) {
            $labels[] = $range->selected === 7 ? $cursor->format('D') : $cursor->format('j M');
            $values[] = (int) Payment::query()
                ->where('status', PaymentStatus::Confirmed)
                ->whereDate('confirmed_at', $cursor->toDateString())
                ->sum('amount_cents');
            $cursor->addDay();
        }

        return [
            'kpis' => [
                ['key' => 'pending_proof_reviews', 'label' => 'Pending proof reviews', 'value' => $pendingProofs, 'href' => '/admin/finance/payment-proofs', 'icon' => 'banknote', 'hint' => 'Current queue'],
                ['key' => 'confirmed_period', 'label' => 'Payments confirmed', 'value' => $confirmedPeriod, 'icon' => 'check', 'hint' => $rangeLabel],
                ['key' => 'rejected_period', 'label' => 'Rejected', 'value' => $rejectedPeriod, 'icon' => 'x', 'hint' => $rangeLabel],
                ['key' => 'failed_period', 'label' => 'Failed', 'value' => $failedPeriod, 'icon' => 'alert', 'hint' => $rangeLabel],
                ['key' => 'revenue_period', 'label' => 'Revenue', 'value' => $revenuePeriod, 'value_format' => 'cents', 'icon' => 'coins', 'hint' => $rangeLabel],
            ],
            'charts' => [
                ['key' => 'finance_revenue_week', 'title' => 'Confirmed revenue by day ('.$rangeLabel.')', 'type' => 'line', 'labels' => $labels, 'values' => $values, 'value_format' => 'cents'],
                [
                    'key' => 'finance_methods_week',
                    'title' => 'Confirmed payments by method ('.$rangeLabel.')',
                    'type' => 'doughnut',
                    'labels' => array_map(fn ($r) => str_replace('_', ' ', $r['method']), $byMethod),
                    'values' => array_map(fn ($r) => $r['count'], $byMethod),
                ],
            ],
            'meta' => [
                'current_date_formatted' => $now->translatedFormat('l, j F Y'),
                'timezone' => (string) config('app.timezone'),
                'date_range' => $range->toArray(),
            ],
        ];
    }
}
