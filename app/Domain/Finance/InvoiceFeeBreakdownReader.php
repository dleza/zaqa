<?php

namespace App\Domain\Finance;

use App\Models\Invoice;

class InvoiceFeeBreakdownReader
{
    /**
     * Fee structure line items captured on an invoice (or supplementary amendment snapshot).
     *
     * @return list<array{
     *   fee_structure_id: int|null,
     *   billing_category_id: int|null,
     *   qualification_type_id: int|null,
     *   qualification_id: int|null,
     *   is_foreign_snapshot: bool,
     *   fee_label_snapshot: string|null,
     *   amount_cents: int
     * }>
     */
    public function linesForPaymentAllocation(Invoice $invoice): array
    {
        $metadata = is_array($invoice->metadata) ? $invoice->metadata : [];

        if ($invoice->supplementary_of_invoice_id !== null) {
            $delta = $this->supplementaryDeltaLines($invoice, $metadata);
            if ($delta !== []) {
                return $delta;
            }
        }

        $breakdown = is_array($metadata['breakdown'] ?? null) ? $metadata['breakdown'] : [];
        if ($breakdown !== []) {
            return $this->normalizeLines($breakdown);
        }

        if ($invoice->fee_structure_id || $invoice->billing_category_id || trim((string) $invoice->fee_label_snapshot) !== '') {
            return [[
                'fee_structure_id' => $invoice->fee_structure_id ? (int) $invoice->fee_structure_id : null,
                'billing_category_id' => $invoice->billing_category_id ? (int) $invoice->billing_category_id : null,
                'qualification_type_id' => $invoice->qualification_type_id ? (int) $invoice->qualification_type_id : null,
                'qualification_id' => null,
                'is_foreign_snapshot' => (bool) $invoice->is_foreign_snapshot,
                'fee_label_snapshot' => $invoice->fee_label_snapshot,
                'amount_cents' => (int) $invoice->amount_cents,
            ]];
        }

        return [[
            'fee_structure_id' => null,
            'billing_category_id' => null,
            'qualification_type_id' => null,
            'qualification_id' => null,
            'is_foreign_snapshot' => (bool) $invoice->is_foreign_snapshot,
            'fee_label_snapshot' => trim((string) $invoice->fee_label_snapshot) !== ''
                ? (string) $invoice->fee_label_snapshot
                : 'Unlinked invoice',
            'amount_cents' => (int) $invoice->amount_cents,
        ]];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return list<array<string, mixed>>
     */
    private function supplementaryDeltaLines(Invoice $supplementary, array $metadata): array
    {
        $after = is_array($metadata['fee_breakdown_after_amendment'] ?? null)
            ? $metadata['fee_breakdown_after_amendment']
            : [];

        if ($after === []) {
            return [];
        }

        $primary = $supplementary->relationLoaded('primaryInvoice')
            ? $supplementary->primaryInvoice
            : Invoice::query()->find($supplementary->supplementary_of_invoice_id);

        $before = is_array($primary?->metadata['breakdown'] ?? null) ? $primary->metadata['breakdown'] : [];
        $beforeByQualification = [];
        foreach ($before as $line) {
            if (! is_array($line)) {
                continue;
            }
            $qualificationId = (int) ($line['qualification_id'] ?? 0);
            if ($qualificationId > 0) {
                $beforeByQualification[$qualificationId] = (int) ($line['amount_cents'] ?? 0);
            }
        }

        $delta = [];
        foreach ($after as $line) {
            if (! is_array($line)) {
                continue;
            }
            $qualificationId = (int) ($line['qualification_id'] ?? 0);
            $afterAmount = (int) ($line['amount_cents'] ?? 0);
            $beforeAmount = $qualificationId > 0 ? (int) ($beforeByQualification[$qualificationId] ?? 0) : 0;
            $lineDelta = $afterAmount - $beforeAmount;

            if ($lineDelta > 0) {
                $normalized = $this->normalizeLine($line);
                $normalized['amount_cents'] = $lineDelta;
                $delta[] = $normalized;
            }
        }

        return $delta;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function normalizeLines(array $lines): array
    {
        return array_values(array_filter(array_map(
            fn ($line) => is_array($line) ? $this->normalizeLine($line) : null,
            $lines,
        )));
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function normalizeLine(array $line): array
    {
        return [
            'fee_structure_id' => isset($line['fee_structure_id']) ? (int) $line['fee_structure_id'] : null,
            'billing_category_id' => isset($line['billing_category_id']) ? (int) $line['billing_category_id'] : null,
            'qualification_type_id' => isset($line['qualification_type_id']) ? (int) $line['qualification_type_id'] : null,
            'qualification_id' => isset($line['qualification_id']) ? (int) $line['qualification_id'] : null,
            'is_foreign_snapshot' => (bool) ($line['is_foreign_snapshot'] ?? false),
            'fee_label_snapshot' => isset($line['fee_label_snapshot']) ? (string) $line['fee_label_snapshot'] : null,
            'amount_cents' => (int) ($line['amount_cents'] ?? 0),
        ];
    }
}
