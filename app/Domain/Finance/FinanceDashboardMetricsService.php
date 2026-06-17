<?php

namespace App\Domain\Finance;

use App\Enums\PaymentStatus;
use App\Models\FeeStructure;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class FinanceDashboardMetricsService
{
    public function __construct(
        private readonly PaymentFeeStructureAllocator $feeStructureAllocator,
    ) {}

    /**
     * @return Builder<Payment>
     */
    public function confirmedPaymentsBetween(Carbon $from, Carbon $to): Builder
    {
        return Payment::query()
            ->where('status', PaymentStatus::Confirmed)
            ->whereBetween('confirmed_at', [$from, $to]);
    }

    public function totalRevenueCents(Carbon $from, Carbon $to): int
    {
        return (int) $this->confirmedPaymentsBetween($from, $to)->sum('amount_cents');
    }

    public function localQualificationRevenueCents(Carbon $from, Carbon $to): int
    {
        return (int) $this->confirmedPaymentsBetween($from, $to)
            ->where(function ($query) {
                $this->applyLocalQualificationScope($query);
            })
            ->sum('amount_cents');
    }

    public function foreignQualificationRevenueCents(Carbon $from, Carbon $to): int
    {
        return (int) $this->confirmedPaymentsBetween($from, $to)
            ->where(function ($query) {
                $this->applyForeignQualificationScope($query);
            })
            ->sum('amount_cents');
    }

    /**
     * @return list<array{
     *   fee_structure_id: int|null,
     *   billing_category_id: int|null,
     *   is_foreign_snapshot: bool,
     *   code: string|null,
     *   label: string,
     *   amount_cents: int,
     *   count: int
     * }>
     */
    public function revenueByFeeStructure(Carbon $from, Carbon $to): array
    {
        $payments = $this->confirmedPaymentsBetween($from, $to)
            ->with([
                'invoice.primaryInvoice',
            ])
            ->orderBy('confirmed_at')
            ->get();

        /** @var array<string, array{fee_structure_id: int|null, billing_category_id: int|null, is_foreign_snapshot: bool, code: string|null, label: string, amount_cents: int, count: int}> $merged */
        $merged = [];

        foreach ($payments as $payment) {
            foreach ($this->feeStructureAllocator->allocatePayment($payment) as $row) {
                $key = $this->mergeKey($row);
                if (! isset($merged[$key])) {
                    $merged[$key] = [
                        'fee_structure_id' => $row['fee_structure_id'],
                        'billing_category_id' => $row['billing_category_id'],
                        'is_foreign_snapshot' => (bool) ($row['is_foreign_snapshot'] ?? false),
                        'code' => $row['code'],
                        'label' => $row['label'],
                        'amount_cents' => 0,
                        'count' => 0,
                    ];
                }

                $merged[$key]['amount_cents'] += (int) $row['amount_cents'];
                $merged[$key]['count'] += (int) $row['count'];
            }
        }

        $this->enrichLabelsFromFeeStructures($merged);

        return collect($merged)
            ->filter(fn (array $row) => $row['amount_cents'] > 0)
            ->map(fn (array $row) => [
                'fee_structure_id' => $row['fee_structure_id'],
                'billing_category_id' => $row['billing_category_id'],
                'is_foreign_snapshot' => (bool) ($row['is_foreign_snapshot'] ?? false),
                'code' => $row['code'],
                'label' => $row['label'],
                'amount_cents' => (int) $row['amount_cents'],
                'count' => (int) $row['count'],
            ])
            ->sortByDesc('amount_cents')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, array{fee_structure_id: int|null, billing_category_id: int|null, code: string|null, label: string, amount_cents: int, count: int}>  $merged
     */
    private function enrichLabelsFromFeeStructures(array &$merged): void
    {
        $feeStructureIds = collect($merged)
            ->pluck('fee_structure_id')
            ->filter(fn ($id) => $id !== null && (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($feeStructureIds === []) {
            return;
        }

        $feeStructures = FeeStructure::query()
            ->with('billingCategory:id,name,code')
            ->whereIn('id', $feeStructureIds)
            ->get()
            ->keyBy('id');

        foreach ($merged as &$row) {
            $feeStructureId = (int) ($row['fee_structure_id'] ?? 0);
            if ($feeStructureId < 1) {
                continue;
            }

            /** @var FeeStructure|null $feeStructure */
            $feeStructure = $feeStructures->get($feeStructureId);
            if (! $feeStructure) {
                continue;
            }

            $category = $feeStructure->billingCategory;
            if ($category) {
                $row['billing_category_id'] = $category->id;
                $row['code'] = $category->code;
                if (trim((string) $row['label']) === '' || $row['label'] === 'Unlinked fee structure') {
                    $row['label'] = $category->name;
                }
            }
        }
        unset($row);
    }

    /**
     * @param  array{fee_structure_id: int|null, billing_category_id: int|null, is_foreign_snapshot?: bool, code: string|null, label: string, amount_cents: int, count: int}  $row
     */
    private function mergeKey(array $row): string
    {
        $feeStructureId = (int) ($row['fee_structure_id'] ?? 0);
        $billingCategoryId = (int) ($row['billing_category_id'] ?? 0);
        $foreign = (bool) ($row['is_foreign_snapshot'] ?? false);

        return $feeStructureId.':'.$billingCategoryId.':'.($foreign ? '1' : '0');
    }

    /**
     * @param  Builder<Payment>  $query
     */
    private function applyLocalQualificationScope(Builder $query): void
    {
        $query->where(function (Builder $inner) {
            $inner->whereHas('invoice', fn (Builder $invoice) => $invoice->where('is_foreign_snapshot', false))
                ->orWhere(function (Builder $withoutInvoice) {
                    $withoutInvoice->whereNull('invoice_id')
                        ->whereHas('application', fn (Builder $application) => $application->where('is_foreign', false));
                });
        });
    }

    /**
     * @param  Builder<Payment>  $query
     */
    private function applyForeignQualificationScope(Builder $query): void
    {
        $query->where(function (Builder $inner) {
            $inner->whereHas('invoice', fn (Builder $invoice) => $invoice->where('is_foreign_snapshot', true))
                ->orWhere(function (Builder $withoutInvoice) {
                    $withoutInvoice->whereNull('invoice_id')
                        ->whereHas('application', fn (Builder $application) => $application->where('is_foreign', true));
                });
        });
    }
}
