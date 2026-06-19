<?php

namespace App\Domain\Finance;

use App\Domain\Audit\AuditLogService;
use App\Enums\InvoiceDocumentType;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Support\Str;

class QuotationConversionService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly InvoiceDocumentPresenter $presenter,
    ) {}

    public function convertToInvoiceOnPayment(Invoice $invoice): Invoice
    {
        if (! $this->presenter->isQuotation($invoice)) {
            if ($invoice->status !== InvoiceStatus::Paid) {
                $invoice->forceFill([
                    'status' => InvoiceStatus::Paid,
                    'paid_at' => $invoice->paid_at ?? now(),
                ])->save();
            }

            return $invoice->fresh();
        }

        $quotationNumber = $invoice->quotation_number ?: $invoice->invoice_number;
        $now = now();

        $invoice->forceFill([
            'quotation_number' => $quotationNumber,
            'invoice_number' => $this->generateInvoiceNumber(),
            'document_type' => InvoiceDocumentType::Invoice,
            'status' => InvoiceStatus::Paid,
            'paid_at' => $invoice->paid_at ?? $now,
            'converted_to_invoice_at' => $now,
            'expires_at' => null,
            'metadata' => array_merge((array) ($invoice->metadata ?? []), [
                'converted_from_quotation_number' => $quotationNumber,
                'converted_at' => $now->toIso8601String(),
            ]),
        ])->save();

        $this->audit->record(
            eventType: 'finance.quotation_converted_to_invoice',
            module: 'Finance',
            actionName: 'quotation_converted_to_invoice',
            message: 'Quotation converted to invoice after successful payment.',
            entityType: Invoice::class,
            entityId: $invoice->id,
            metadata: [
                'application_id' => $invoice->application_id,
                'quotation_number' => $quotationNumber,
                'invoice_number' => $invoice->invoice_number,
            ],
        );

        return $invoice->fresh();
    }

    private function generateInvoiceNumber(): string
    {
        return 'INV-'.now()->format('Y').'-'.Str::upper(Str::random(10));
    }
}
