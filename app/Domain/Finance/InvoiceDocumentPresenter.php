<?php

namespace App\Domain\Finance;

use App\Enums\InvoiceDocumentType;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Support\Str;

class InvoiceDocumentPresenter
{
    public function isQuotation(Invoice $invoice): bool
    {
        return $invoice->document_type === InvoiceDocumentType::Quotation
            || (
                $invoice->document_type === null
                && $invoice->status !== InvoiceStatus::Paid
                && $invoice->supplementary_of_invoice_id === null
            );
    }

    public function isInvoice(Invoice $invoice): bool
    {
        return ! $this->isQuotation($invoice);
    }

    public function documentTitle(Invoice $invoice): string
    {
        return $this->isQuotation($invoice) ? 'Quotation' : 'Invoice';
    }

    public function documentNumber(Invoice $invoice): string
    {
        if ($this->isQuotation($invoice)) {
            return (string) ($invoice->quotation_number ?: $invoice->invoice_number);
        }

        return (string) $invoice->invoice_number;
    }

    public function downloadButtonLabel(Invoice $invoice): string
    {
        return $this->isQuotation($invoice) ? 'Download quotation' : 'Download invoice';
    }

    public function filenamePrefix(Invoice $invoice): string
    {
        return $this->isQuotation($invoice) ? 'quotation' : 'invoice';
    }

    public function numberFieldLabel(Invoice $invoice): string
    {
        return $this->isQuotation($invoice) ? 'Quotation Number' : 'Invoice Number';
    }

    public function dateFieldLabel(Invoice $invoice): string
    {
        return $this->isQuotation($invoice) ? 'Quotation Date' : 'Invoice Date';
    }

    /**
     * @return array<string, mixed>
     */
    public function applicantPayload(Invoice $invoice): array
    {
        return [
            'document_type' => $this->isQuotation($invoice) ? 'quotation' : 'invoice',
            'document_title' => $this->documentTitle($invoice),
            'document_number' => $this->documentNumber($invoice),
            'download_label' => $this->downloadButtonLabel($invoice),
            'expires_at' => optional($invoice->expires_at)?->toIso8601String(),
            'converted_to_invoice_at' => optional($invoice->converted_to_invoice_at)?->toIso8601String(),
            'quotation_number' => $invoice->quotation_number,
        ];
    }

    public function countsForFinanceReports(): callable
    {
        return fn ($query) => $query
            ->where('document_type', InvoiceDocumentType::Invoice->value)
            ->where('status', InvoiceStatus::Paid->value);
    }
}
