<?php

namespace App\Http\Controllers\Admin\Finance;

use App\Domain\Audit\AuditLogService;
use App\Domain\Documents\ApplicantDocumentService;
use App\Domain\Finance\PaymentProofReviewService;
use App\Domain\Finance\PaymentSearchService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ReviewPaymentProofRequest;
use App\Models\Payment;
use App\Models\QualificationDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminFinancePaymentProofController extends Controller
{
    public function index(Request $request, PaymentSearchService $search): Response
    {
        $payments = $search->proofQueue($request);

        return Inertia::render('Admin/Finance/PaymentProofs/Index', [
            'filters' => $request->only([
                'q',
                'status',
                'method',
                'currency',
                'reviewed_by',
                'amount_min',
                'amount_max',
                'uploaded_from',
                'uploaded_to',
                'is_foreign',
            ]),
            'payments' => $payments->through(fn (Payment $p) => $this->mapPaymentRow($p)),
        ]);
    }

    public function show(Request $request, Payment $payment, AuditLogService $audit): Response
    {
        $payment->loadMissing(['application.applicant', 'invoice', 'proofDocument', 'reviewedBy']);

        $audit->record(
            eventType: 'finance.payment_proof_viewed',
            module: 'Finance',
            actionName: 'payment_proof_viewed',
            message: 'Finance viewed payment proof detail.',
            entityType: Payment::class,
            entityId: $payment->id,
            metadata: [
                'application_id' => $payment->application_id,
                'invoice_id' => $payment->invoice_id,
                'status' => $payment->status?->value ?? (string) $payment->status,
            ],
            actor: $request->user(),
        );

        return Inertia::render('Admin/Finance/PaymentProofs/Show', [
            'payment' => $this->mapPaymentDetail($payment),
            'can' => [
                'approve' => (bool) $request->user()?->can('finance.payment_proofs.approve'),
                'reject' => (bool) $request->user()?->can('finance.payment_proofs.reject'),
            ],
        ]);
    }

    public function approve(ReviewPaymentProofRequest $request, Payment $payment, PaymentProofReviewService $reviews): RedirectResponse
    {
        $this->authorizeFinanceReview($request);

        $reviews->approve($payment, $request->user(), $request->validated()['comment'] ?? null);

        return back()->with('success', 'Payment proof approved. Payment has been confirmed.');
    }

    public function reject(ReviewPaymentProofRequest $request, Payment $payment, PaymentProofReviewService $reviews): RedirectResponse
    {
        $this->authorizeFinanceReview($request);

        $reason = (string) ($request->validated()['reason'] ?? '');
        $reviews->reject($payment, $request->user(), $reason);

        return back()->with('success', 'Payment proof rejected. Applicant will need to upload a new proof.');
    }

    public function preview(Request $request, QualificationDocument $document, ApplicantDocumentService $documents, AuditLogService $audit)
    {
        $audit->record(
            eventType: 'finance.document_previewed',
            module: 'Finance',
            actionName: 'document_previewed',
            message: 'Finance previewed a document.',
            entityType: QualificationDocument::class,
            entityId: $document->id,
            metadata: [
                'application_id' => $document->application_id,
                'document_type' => $document->document_type?->value ?? (string) $document->document_type,
            ],
            actor: $request->user(),
        );

        return $documents->previewResponse($document);
    }

    public function download(Request $request, QualificationDocument $document, ApplicantDocumentService $documents, AuditLogService $audit)
    {
        $audit->record(
            eventType: 'finance.document_downloaded',
            module: 'Finance',
            actionName: 'document_downloaded',
            message: 'Finance downloaded a document.',
            entityType: QualificationDocument::class,
            entityId: $document->id,
            metadata: [
                'application_id' => $document->application_id,
                'document_type' => $document->document_type?->value ?? (string) $document->document_type,
            ],
            actor: $request->user(),
        );

        return $documents->downloadResponse($document);
    }

    /**
     * Finance routes are permission-gated; this is a defense-in-depth check so the request class does not remain permissive.
     */
    private function authorizeFinanceReview(Request $request): void
    {
        if (! $request->user() || ! $request->user()->can('finance.payment_proofs.review')) {
            abort(403);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function mapPaymentRow(Payment $p): array
    {
        $p->loadMissing(['application.applicant', 'invoice', 'proofDocument', 'reviewedBy']);

        return [
            'id' => $p->id,
            'method' => $p->method?->value ?? (string) $p->method,
            'status' => $p->status?->value ?? (string) $p->status,
            'currency' => $p->currency,
            'amount_cents' => (int) $p->amount_cents,
            'created_at' => optional($p->created_at)?->toIso8601String(),
            'awaiting_finance_review_at' => optional($p->awaiting_finance_review_at)?->toIso8601String(),
            'reviewed_at' => optional($p->reviewed_at)?->toIso8601String(),
            'reviewed_by' => $p->reviewedBy?->name,
            'application' => [
                'id' => $p->application?->id,
                'application_number' => $p->application?->application_number,
                'is_foreign' => (bool) ($p->application?->is_foreign ?? false),
            ],
            'applicant' => [
                'name' => $p->application?->applicant?->name,
                'email' => $p->application?->applicant?->email,
                'phone' => $p->application?->applicant?->phone_primary,
            ],
            'invoice' => [
                'id' => $p->invoice?->id,
                'invoice_number' => $p->invoice?->invoice_number,
                'status' => $p->invoice?->status?->value ?? (string) ($p->invoice?->status ?? ''),
                'download_url' => $p->invoice
                    ? route('admin.finance.invoices.download', ['invoice' => $p->invoice->id])
                    : null,
            ],
            'proof_document' => $p->proofDocument
                ? [
                    'id' => $p->proofDocument->id,
                    'original_name' => $p->proofDocument->original_name,
                    'preview_url' => route('admin.finance.documents.preview', ['document' => $p->proofDocument->id]),
                    'download_url' => route('admin.finance.documents.download', ['document' => $p->proofDocument->id]),
                ]
                : null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function mapPaymentDetail(Payment $p): array
    {
        return [
            ...$this->mapPaymentRow($p),
            'provider' => $p->provider,
            'provider_reference' => $p->provider_reference,
            'provider_transaction_id' => $p->provider_transaction_id,
            'review_comment' => $p->review_comment,
            'rejection_reason' => $p->rejection_reason,
            'confirmed_at' => optional($p->confirmed_at)?->toIso8601String(),
            'rejected_at' => optional($p->rejected_at)?->toIso8601String(),
            'failed_at' => optional($p->failed_at)?->toIso8601String(),
        ];
    }
}
