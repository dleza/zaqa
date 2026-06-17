<?php

namespace App\Domain\Finance;

use App\Models\FeeStructure;
use App\Models\Payment;
use Illuminate\Support\Collection;

class PaymentFeeStructureAllocator
{
    public function __construct(
        private readonly InvoiceFeeBreakdownReader $breakdownReader,
    ) {}

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
    public function allocatePayment(Payment $payment): array
    {
        $invoice = $payment->invoice;
        if (! $invoice) {
            return [[
                'fee_structure_id' => null,
                'billing_category_id' => null,
                'is_foreign_snapshot' => false,
                'code' => null,
                'label' => 'Unlinked payment',
                'amount_cents' => (int) $payment->amount_cents,
                'count' => 1,
            ]];
        }

        $lines = $this->breakdownReader->linesForPaymentAllocation($invoice);
        $invoiceLineTotal = (int) collect($lines)->sum('amount_cents');
        $paymentCents = (int) $payment->amount_cents;

        if ($invoiceLineTotal <= 0 || $paymentCents <= 0) {
            return [];
        }

        $allocations = [];
        $remaining = $paymentCents;
        $lineCount = count($lines);

        foreach ($lines as $index => $line) {
            $isLast = $index === $lineCount - 1;
            $lineAmount = (int) $line['amount_cents'];
            $allocated = $isLast
                ? $remaining
                : (int) round($paymentCents * ($lineAmount / $invoiceLineTotal));
            $remaining -= $allocated;

            if ($allocated <= 0) {
                continue;
            }

            $groupKey = $this->groupKey($line);
            if (! isset($allocations[$groupKey])) {
                $allocations[$groupKey] = [
                    'fee_structure_id' => $line['fee_structure_id'],
                    'billing_category_id' => $line['billing_category_id'],
                    'is_foreign_snapshot' => (bool) ($line['is_foreign_snapshot'] ?? false),
                    'code' => null,
                    'label' => $this->lineLabel($line),
                    'amount_cents' => 0,
                    'count' => 0,
                ];
            }

            $allocations[$groupKey]['amount_cents'] += $allocated;
            $allocations[$groupKey]['count']++;
        }

        return array_values($allocations);
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function groupKey(array $line): string
    {
        $feeStructureId = (int) ($line['fee_structure_id'] ?? 0);
        $billingCategoryId = (int) ($line['billing_category_id'] ?? 0);
        $foreign = (bool) ($line['is_foreign_snapshot'] ?? false);

        return $feeStructureId.':'.$billingCategoryId.':'.($foreign ? '1' : '0');
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function lineLabel(array $line): string
    {
        $snapshot = trim((string) ($line['fee_label_snapshot'] ?? ''));
        if ($snapshot !== '') {
            return $snapshot;
        }

        $feeStructureId = (int) ($line['fee_structure_id'] ?? 0);
        if ($feeStructureId > 0) {
            $feeStructure = FeeStructure::query()
                ->with('billingCategory:id,name,code')
                ->find($feeStructureId);

            $categoryName = trim((string) ($feeStructure?->billingCategory?->name ?? ''));
            if ($categoryName !== '') {
                return $categoryName;
            }
        }

        return 'Unlinked fee structure';
    }
}
