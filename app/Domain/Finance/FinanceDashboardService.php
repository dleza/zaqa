<?php

namespace App\Domain\Finance;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Support\Carbon;

class FinanceDashboardService
{
    /**
     * @return array<string,mixed>
     */
    public function build(): array
    {
        $now = Carbon::now();
        $today = $now->toDateString();
        $weekStart = $now->copy()->startOfWeek(Carbon::MONDAY);
        $weekEnd = $now->copy()->endOfWeek(Carbon::SUNDAY);

        $pendingProofs = Payment::query()
            ->where('status', PaymentStatus::AwaitingFinanceReview)
            ->whereIn('method', [PaymentMethod::BankDeposit, PaymentMethod::BankTransfer])
            ->count();

        $confirmedToday = Payment::query()
            ->where('status', PaymentStatus::Confirmed)
            ->whereDate('confirmed_at', $today)
            ->count();

        $rejectedToday = Payment::query()
            ->where('status', PaymentStatus::Rejected)
            ->whereDate('reviewed_at', $today)
            ->count();

        $failedToday = Payment::query()
            ->where('status', PaymentStatus::Failed)
            ->whereDate('failed_at', $today)
            ->count();

        $revenueToday = (int) Payment::query()
            ->where('status', PaymentStatus::Confirmed)
            ->whereDate('confirmed_at', $today)
            ->sum('amount_cents');

        $revenueWeek = (int) Payment::query()
            ->where('status', PaymentStatus::Confirmed)
            ->whereBetween('confirmed_at', [$weekStart, $weekEnd])
            ->sum('amount_cents');

        $byMethod = [];
        foreach (PaymentMethod::cases() as $m) {
            $byMethod[] = [
                'method' => $m->value,
                'count' => (int) Payment::query()
                    ->where('status', PaymentStatus::Confirmed)
                    ->whereBetween('confirmed_at', [$weekStart, $weekEnd])
                    ->where('method', $m)
                    ->count(),
            ];
        }

        $labels = [];
        $values = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $weekStart->copy()->addDays($i);
            $labels[] = $d->format('D');
            $values[] = (int) Payment::query()
                ->where('status', PaymentStatus::Confirmed)
                ->whereDate('confirmed_at', $d->toDateString())
                ->sum('amount_cents');
        }

        return [
            'kpis' => [
                ['key' => 'pending_proof_reviews', 'label' => 'Pending proof reviews', 'value' => $pendingProofs, 'href' => '/admin/finance/payment-proofs', 'icon' => 'banknote'],
                ['key' => 'confirmed_today', 'label' => 'Payments confirmed today', 'value' => $confirmedToday, 'icon' => 'check'],
                ['key' => 'rejected_today', 'label' => 'Rejected today', 'value' => $rejectedToday, 'icon' => 'x'],
                ['key' => 'failed_today', 'label' => 'Failed today', 'value' => $failedToday, 'icon' => 'alert'],
                ['key' => 'revenue_today', 'label' => 'Revenue today', 'value' => $revenueToday, 'value_format' => 'cents', 'icon' => 'coins'],
                ['key' => 'revenue_week', 'label' => 'Revenue this week', 'value' => $revenueWeek, 'value_format' => 'cents', 'icon' => 'trending'],
            ],
            'charts' => [
                ['key' => 'finance_revenue_week', 'title' => 'Confirmed revenue by day (this week)', 'type' => 'line', 'labels' => $labels, 'values' => $values, 'value_format' => 'cents'],
                [
                    'key' => 'finance_methods_week',
                    'title' => 'Confirmed payments by method (week)',
                    'type' => 'doughnut',
                    'labels' => array_map(fn ($r) => str_replace('_', ' ', $r['method']), $byMethod),
                    'values' => array_map(fn ($r) => $r['count'], $byMethod),
                ],
            ],
            'meta' => [
                'current_date_formatted' => $now->translatedFormat('l, j F Y'),
                'timezone' => (string) config('app.timezone'),
            ],
        ];
    }
}
