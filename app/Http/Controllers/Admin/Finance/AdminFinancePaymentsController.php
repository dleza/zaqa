<?php

namespace App\Http\Controllers\Admin\Finance;

use App\Domain\Audit\AuditLogService;
use App\Domain\Finance\PaymentSearchService;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentWebhookLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminFinancePaymentsController extends Controller
{
    public function index(Request $request, PaymentSearchService $search): Response
    {
        $payments = $search->payments($request);

        return Inertia::render('Admin/Finance/Payments/Index', [
            'filters' => $request->only([
                'q',
                'status',
                'method',
                'provider',
                'currency',
                'reviewed_by',
                'amount_min',
                'amount_max',
                'initiated_from',
                'initiated_to',
                'confirmed_from',
                'confirmed_to',
                'is_foreign',
            ]),
            'payments' => $payments->through(fn (Payment $p) => $this->mapPaymentRow($p)),
        ]);
    }

    public function show(Request $request, Payment $payment, AuditLogService $audit): Response
    {
        $payment->loadMissing(['application.applicant', 'invoice', 'proofDocument', 'reviewedBy', 'attempts']);

        $audit->record(
            eventType: 'finance.payment_viewed',
            module: 'Finance',
            actionName: 'payment_viewed',
            message: 'Finance viewed payment detail.',
            entityType: Payment::class,
            entityId: $payment->id,
            metadata: [
                'application_id' => $payment->application_id,
                'invoice_id' => $payment->invoice_id,
                'status' => $payment->status?->value ?? (string) $payment->status,
                'provider' => $payment->provider,
            ],
            actor: $request->user(),
        );

        $webhooks = PaymentWebhookLog::query()
            ->where('payment_id', $payment->id)
            ->orderByDesc('id')
            ->limit(8)
            ->get(['id', 'provider', 'event_type', 'process_status', 'received_at', 'processed_at', 'error_message'])
            ->map(fn (PaymentWebhookLog $w) => [
                'id' => $w->id,
                'provider' => $w->provider,
                'event_type' => $w->event_type,
                'process_status' => $w->process_status,
                'received_at' => optional($w->received_at)?->toIso8601String(),
                'processed_at' => optional($w->processed_at)?->toIso8601String(),
                'error_message' => $w->error_message,
            ])
            ->values();

        return Inertia::render('Admin/Finance/Payments/Show', [
            'payment' => [
                ...$this->mapPaymentRow($payment),
                'provider' => $payment->provider,
                'provider_reference' => $payment->provider_reference,
                'provider_transaction_id' => $payment->provider_transaction_id,
                'mobile_number' => $payment->mobile_number,
                'attempts' => $payment->attempts
                    ->sortByDesc('id')
                    ->values()
                    ->map(fn ($a) => [
                        'id' => $a->id,
                        'gateway' => $a->gateway,
                        'method' => $a->method,
                        'status' => $a->status?->value ?? (string) $a->status,
                        'payment_reference' => $a->payment_reference,
                        'provider_transaction_id' => $a->provider_transaction_id,
                        'mobile_number' => $a->mobile_number,
                        'currency' => $a->currency,
                        'amount_cents' => (int) $a->amount_cents,
                        'response_code' => $a->response_code,
                        'response_message' => $a->response_message,
                        'query_attempts' => (int) $a->query_attempts,
                        'initiated_at' => optional($a->initiated_at)?->toIso8601String(),
                        'confirmed_at' => optional($a->confirmed_at)?->toIso8601String(),
                        'failed_at' => optional($a->failed_at)?->toIso8601String(),
                        'rejected_at' => optional($a->rejected_at)?->toIso8601String(),
                        'expired_at' => optional($a->expired_at)?->toIso8601String(),
                        'last_queried_at' => optional($a->last_queried_at)?->toIso8601String(),
                        'next_query_at' => optional($a->next_query_at)?->toIso8601String(),
                        'created_at' => optional($a->created_at)?->toIso8601String(),
                    ])
                    ->all(),
                'review_comment' => $payment->review_comment,
                'rejection_reason' => $payment->rejection_reason,
                'initiated_at' => optional($payment->initiated_at)?->toIso8601String(),
                'confirmed_at' => optional($payment->confirmed_at)?->toIso8601String(),
                'failed_at' => optional($payment->failed_at)?->toIso8601String(),
                'rejected_at' => optional($payment->rejected_at)?->toIso8601String(),
                'expires_at' => optional($payment->expires_at)?->toIso8601String(),
                'raw_payload' => $payment->raw_payload,
            ],
            'webhooks' => $webhooks,
        ]);
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
            'provider' => $p->provider,
            'provider_reference' => $p->provider_reference,
            'provider_transaction_id' => $p->provider_transaction_id,
            'created_at' => optional($p->created_at)?->toIso8601String(),
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
}
