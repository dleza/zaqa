<?php

namespace App\Http\Controllers\Applicant;

use App\Domain\Finance\InvoicePdfService;
use App\Domain\Finance\PaymentReceiptPdfService;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ApplicantBillingController extends Controller
{
    public function invoices(Request $request): Response
    {
        $user = $request->user();

        $invoices = Invoice::query()
            ->with(['application'])
            ->whereHas('application', fn ($q) => $q->where('applicant_user_id', $user->id))
            ->latest('id')
            ->get()
            ->map(fn (Invoice $inv) => [
                'id' => $inv->id,
                'invoice_number' => $inv->invoice_number,
                'currency' => $inv->currency,
                'amount_cents' => $inv->amount_cents,
                'status' => $inv->status?->value ?? (string) $inv->status,
                'issued_at' => optional($inv->issued_at)?->toIso8601String(),
                'paid_at' => optional($inv->paid_at)?->toIso8601String(),
                'application' => $inv->application
                    ? [
                        'id' => $inv->application->id,
                        'application_number' => $inv->application->application_number,
                        'current_status' => $inv->application->current_status?->value ?? (string) $inv->application->current_status,
                    ]
                    : null,
                'download_url' => route('applicant.invoices.download', $inv),
            ]);

        return Inertia::render('Applicant/Invoices', [
            'invoices' => $invoices,
        ]);
    }

    public function payments(Request $request): Response
    {
        $user = $request->user();
        $receiptPdf = app(PaymentReceiptPdfService::class);

        $payments = Payment::query()
            ->with(['application', 'invoice', 'proofDocument'])
            ->whereHas('application', fn ($q) => $q->where('applicant_user_id', $user->id))
            ->latest('id')
            ->get()
            ->map(fn (Payment $p) => $this->mapPaymentListRow($p, $receiptPdf, includeProofDocument: true));

        $summary = [
            'total_cents' => (int) $payments->sum('amount_cents'),
            'confirmed_cents' => (int) $payments->where('status', 'confirmed')->sum('amount_cents'),
            'count' => (int) $payments->count(),
        ];

        return Inertia::render('Applicant/Payments', [
            'payments' => $payments,
            'summary' => $summary,
        ]);
    }

    public function receipts(Request $request): Response
    {
        $user = $request->user();
        $receiptPdf = app(PaymentReceiptPdfService::class);

        $receipts = Payment::query()
            ->with(['application', 'invoice'])
            ->where('status', PaymentStatus::Confirmed)
            ->whereHas('application', fn ($q) => $q->where('applicant_user_id', $user->id))
            ->latest('confirmed_at')
            ->latest('id')
            ->get()
            ->map(fn (Payment $p) => $this->mapReceiptListRow($p, $receiptPdf));

        return Inertia::render('Applicant/Receipts', [
            'receipts' => $receipts,
            'summary' => [
                'count' => (int) $receipts->count(),
                'total_cents' => (int) $receipts->sum('amount_cents'),
            ],
        ]);
    }

    public function showInvoice(Request $request, Invoice $invoice, InvoicePdfService $pdf): Response
    {
        $invoice->load([
            'application',
            'payments' => fn ($q) => $q->latest('id'),
        ]);

        $this->assertApplicantOwnsApplication($request, $invoice->application);

        return Inertia::render('Applicant/InvoiceShow', [
            'document' => $pdf->buildWebViewData($invoice),
            'invoice' => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'currency' => $invoice->currency,
                'amount_cents' => $invoice->amount_cents,
                'status' => $invoice->status?->value ?? (string) $invoice->status,
                'issued_at' => optional($invoice->issued_at)?->toIso8601String(),
                'due_at' => optional($invoice->due_at)?->toIso8601String(),
                'paid_at' => optional($invoice->paid_at)?->toIso8601String(),
                'fee_label_snapshot' => $invoice->fee_label_snapshot,
                'processing_days_snapshot' => $invoice->processing_days_snapshot,
                'is_foreign_snapshot' => (bool) $invoice->is_foreign_snapshot,
                'application' => $invoice->application
                    ? [
                        'id' => $invoice->application->id,
                        'application_number' => $invoice->application->application_number,
                        'current_status' => $invoice->application->current_status?->value ?? (string) $invoice->application->current_status,
                        'show_url' => route('applicant.applications.show', $invoice->application->id),
                        'edit_url' => route('applicant.applications.edit', ['application' => $invoice->application->id]),
                        'track_url' => route('applicant.applications.track', $invoice->application->id),
                        'can_edit' => $request->user()->can('update', $invoice->application),
                    ]
                    : null,
                'payments' => $invoice->payments->map(fn (Payment $p) => [
                    'id' => $p->id,
                    'method' => $p->method?->value ?? (string) $p->method,
                    'status' => $p->status?->value ?? (string) $p->status,
                    'currency' => $p->currency,
                    'amount_cents' => $p->amount_cents,
                    'confirmed_at' => optional($p->confirmed_at)?->toIso8601String(),
                    'created_at' => optional($p->created_at)?->toIso8601String(),
                    'show_url' => route('applicant.payments.show', $p->id),
                    'receipt_download_url' => app(PaymentReceiptPdfService::class)->receiptDownloadUrl($p, 'applicant.payments.receipt.download'),
                ])->values()->all(),
                'download_url' => route('applicant.invoices.download', $invoice),
            ],
        ]);
    }

    public function downloadInvoice(Request $request, Invoice $invoice, InvoicePdfService $pdf): SymfonyResponse
    {
        $invoice->loadMissing('application');
        $this->assertApplicantOwnsApplication($request, $invoice->application);

        return $pdf->downloadResponse($invoice);
    }

    public function downloadReceipt(Request $request, Payment $payment, PaymentReceiptPdfService $pdf): SymfonyResponse
    {
        $payment->loadMissing('application');
        $this->assertApplicantOwnsApplication($request, $payment->application);

        if (! $pdf->isEligible($payment)) {
            abort(404);
        }

        return $pdf->downloadResponse($payment);
    }

    public function showPayment(Request $request, Payment $payment, PaymentReceiptPdfService $receiptPdf): Response
    {
        $payment->load(['application', 'invoice', 'proofDocument']);

        $this->assertApplicantOwnsApplication($request, $payment->application);

        $signedExpiry = now()->addMinutes(30);

        $proof = $payment->proofDocument;

        return Inertia::render('Applicant/PaymentShow', [
            'document' => $receiptPdf->buildWebViewData($payment),
            'payment' => [
                'id' => $payment->id,
                'method' => $payment->method?->value ?? (string) $payment->method,
                'status' => $payment->status?->value ?? (string) $payment->status,
                'currency' => $payment->currency,
                'amount_cents' => $payment->amount_cents,
                'provider' => $payment->provider,
                'provider_reference' => $payment->provider_reference,
                'provider_transaction_id' => $payment->provider_transaction_id,
                'mobile_number' => $payment->mobile_number,
                'created_at' => optional($payment->created_at)?->toIso8601String(),
                'initiated_at' => optional($payment->initiated_at)?->toIso8601String(),
                'confirmed_at' => optional($payment->confirmed_at)?->toIso8601String(),
                'failed_at' => optional($payment->failed_at)?->toIso8601String(),
                'rejected_at' => optional($payment->rejected_at)?->toIso8601String(),
                'rejection_reason' => $payment->rejection_reason,
                'review_comment' => $payment->review_comment,
                'application' => $payment->application
                    ? [
                        'id' => $payment->application->id,
                        'application_number' => $payment->application->application_number,
                        'current_status' => $payment->application->current_status?->value ?? (string) $payment->application->current_status,
                        'show_url' => route('applicant.applications.show', $payment->application->id),
                        'edit_url' => route('applicant.applications.edit', ['application' => $payment->application->id]),
                        'track_url' => route('applicant.applications.track', $payment->application->id),
                        'can_edit' => $request->user()->can('update', $payment->application),
                    ]
                    : null,
                'invoice' => $payment->invoice
                    ? [
                        'id' => $payment->invoice->id,
                        'invoice_number' => $payment->invoice->invoice_number,
                        'status' => $payment->invoice->status?->value ?? (string) $payment->invoice->status,
                        'show_url' => route('applicant.invoices.show', $payment->invoice->id),
                        'download_url' => route('applicant.invoices.download', $payment->invoice),
                    ]
                    : null,
                'proof_document' => $proof
                    ? [
                        'id' => $proof->id,
                        'original_name' => $proof->original_name,
                        'preview_url' => URL::temporarySignedRoute('applicant.documents.preview', $signedExpiry, ['document' => $proof->id]),
                        'download_url' => URL::temporarySignedRoute('applicant.documents.download', $signedExpiry, ['document' => $proof->id]),
                    ]
                    : null,
                'receipt_download_url' => $receiptPdf->receiptDownloadUrl($payment, 'applicant.payments.receipt.download'),
            ],
        ]);
    }

    private function assertApplicantOwnsApplication(Request $request, ?Application $application): void
    {
        $userId = (int) $request->user()?->id;
        if (! $application || (int) $application->applicant_user_id !== $userId) {
            abort(403);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function mapPaymentListRow(Payment $p, PaymentReceiptPdfService $receiptPdf, bool $includeProofDocument = false): array
    {
        $row = [
            'id' => $p->id,
            'method' => $p->method?->value ?? (string) $p->method,
            'status' => $p->status?->value ?? (string) $p->status,
            'currency' => $p->currency,
            'amount_cents' => $p->amount_cents,
            'provider' => $p->provider,
            'provider_reference' => $p->provider_reference,
            'created_at' => optional($p->created_at)?->toIso8601String(),
            'confirmed_at' => optional($p->confirmed_at)?->toIso8601String(),
            'rejection_reason' => $p->rejection_reason,
            'application' => $p->application
                ? [
                    'id' => $p->application->id,
                    'application_number' => $p->application->application_number,
                ]
                : null,
            'invoice' => $p->invoice
                ? [
                    'id' => $p->invoice->id,
                    'invoice_number' => $p->invoice->invoice_number,
                ]
                : null,
            'receipt_download_url' => $receiptPdf->receiptDownloadUrl($p, 'applicant.payments.receipt.download'),
        ];

        if ($includeProofDocument) {
            $row['proof_document'] = $p->proofDocument
                ? [
                    'id' => $p->proofDocument->id,
                    'preview_url' => URL::temporarySignedRoute('applicant.documents.preview', now()->addMinutes(15), ['document' => $p->proofDocument->id]),
                    'download_url' => URL::temporarySignedRoute('applicant.documents.download', now()->addMinutes(15), ['document' => $p->proofDocument->id]),
                ]
                : null;
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapReceiptListRow(Payment $p, PaymentReceiptPdfService $receiptPdf): array
    {
        return [
            'id' => $p->id,
            'receipt_number_display' => 'ZQ '.$p->id,
            'method' => $p->method?->value ?? (string) $p->method,
            'currency' => $p->currency,
            'amount_cents' => $p->amount_cents,
            'provider_reference' => $p->provider_reference,
            'confirmed_at' => optional($p->confirmed_at)?->toIso8601String(),
            'application' => $p->application
                ? [
                    'id' => $p->application->id,
                    'application_number' => $p->application->application_number,
                ]
                : null,
            'invoice' => $p->invoice
                ? [
                    'id' => $p->invoice->id,
                    'invoice_number' => $p->invoice->invoice_number,
                ]
                : null,
            'show_url' => route('applicant.payments.show', $p),
            'receipt_download_url' => $receiptPdf->receiptDownloadUrl($p, 'applicant.payments.receipt.download'),
        ];
    }
}

