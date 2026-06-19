<?php

namespace App\Domain\Payments;

use App\Domain\Audit\AuditLogService;
use App\Domain\Fees\QualificationFeeResolver;
use App\Domain\Tracking\ApplicationLifecycleService;
use App\Enums\ApplicationStatus;
use App\Enums\InvoiceDocumentType;
use App\Enums\InvoiceStatus;
use App\Enums\LifecycleStage;
use App\Enums\LifecycleVisibility;
use App\Enums\PaymentStatus;
use App\Models\Application;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Qualification;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly QualificationFeeResolver $fees,
        private readonly ApplicationLifecycleService $lifecycle,
        private readonly ApplicationPaymentSatisfaction $paymentSatisfaction,
    ) {}

    public function ensureInvoice(Application $application, User $actor): Invoice
    {
        return DB::transaction(function () use ($application, $actor) {
            $primary = Invoice::query()
                ->where('application_id', $application->id)
                ->whereNull('supplementary_of_invoice_id')
                ->lockForUpdate()
                ->first();

            $application->loadMissing('qualifications');

            // When an invoice exists for a draft application, the applicant has entered the payment journey.
            // Keep it editable until payment satisfaction triggers automatic submission.
            if (
                $application->current_status === ApplicationStatus::Draft
                && $primary
                && in_array($primary->status, [InvoiceStatus::Issued, InvoiceStatus::Draft], true)
            ) {
                $application->forceFill(['current_status' => ApplicationStatus::PendingPayment])->save();
            }

            if ($application->qualifications->count() < 1) {
                throw ValidationException::withMessages([
                    'qualifications' => 'Add at least one qualification before billing.',
                ]);
            }

            $now = Carbon::now();
            $totalCents = 0;
            $currency = null;
            $breakdown = [];

            /** @var Qualification $q */
            foreach ($application->qualifications as $q) {
                $typeId = (int) ($q->qualification_type_id ?? 0);
                if ($typeId < 1) {
                    throw ValidationException::withMessages([
                        'qualification_type_id' => 'Each qualification must have a qualification type before billing.',
                    ]);
                }

                $resolved = $this->fees->resolve($typeId, (bool) $q->is_foreign_qualification, $now);
                $lineCurrency = (string) $resolved['currency'];
                $currency = $currency ?? $lineCurrency;
                if ($currency !== $lineCurrency) {
                    throw ValidationException::withMessages([
                        'currency' => 'Mixed currencies are not supported for a single invoice.',
                    ]);
                }

                $feeCents = (int) $resolved['fee_cents'];
                $totalCents += $feeCents;

                $q->forceFill([
                    'fee_currency' => $currency,
                    'fee_amount_cents' => $feeCents,
                ])->save();

                $breakdown[] = [
                    'qualification_id' => $q->id,
                    'qualification_type_id' => $resolved['qualification_type']->id,
                    'billing_category_id' => $resolved['billing_category']->id,
                    'fee_structure_id' => $resolved['fee_structure']->id,
                    'is_foreign_snapshot' => (bool) $q->is_foreign_qualification,
                    'fee_label_snapshot' => $resolved['billing_category']->name,
                    'amount_cents' => $feeCents,
                    'currency' => $currency,
                    'processing_days_snapshot' => $resolved['processing_days'],
                ];
            }

            if ($primary) {
                if ($primary->status === InvoiceStatus::Paid || $primary->paid_at) {
                    $this->syncSupplementaryForPaidPrimary($primary, $application, $actor, $totalCents, $currency, $breakdown);
                    $application->refresh()->loadMissing('payments', 'qualifications');

                    return $this->resolveInvoiceForPaymentFlow($application, $primary);
                }

                if ($primary->status !== InvoiceStatus::Issued) {
                    return $this->resolveInvoiceForPaymentFlow($application, $primary);
                }

                $application->loadMissing('payments');
                $hasConfirmedPayment = $application->payments->contains(fn ($p) => $p->status?->value === 'confirmed');
                if ($hasConfirmedPayment) {
                    return $this->resolveInvoiceForPaymentFlow($application, $primary);
                }

                $needsUpdate =
                    (int) $primary->amount_cents !== (int) $totalCents
                    || (string) $primary->currency !== (string) $currency;

                if (! $needsUpdate) {
                    return $this->resolveInvoiceForPaymentFlow($application, $primary);
                }

                $before = $primary->only([
                    'billing_category_id',
                    'qualification_type_id',
                    'fee_structure_id',
                    'is_foreign_snapshot',
                    'processing_days_snapshot',
                    'fee_label_snapshot',
                    'currency',
                    'amount_cents',
                ]);

                $primary->forceFill([
                    'billing_category_id' => null,
                    'qualification_type_id' => null,
                    'fee_structure_id' => null,
                    'is_foreign_snapshot' => (bool) $application->is_foreign,
                    'processing_days_snapshot' => null,
                    'fee_label_snapshot' => 'Multiple qualifications',
                    'currency' => $currency,
                    'amount_cents' => $totalCents,
                    'metadata' => array_merge((array) ($primary->metadata ?? []), [
                        'breakdown' => $breakdown,
                        'recalculated_at' => now()->toIso8601String(),
                    ]),
                ])->save();

                Payment::query()
                    ->where('invoice_id', $primary->id)
                    ->where('status', '!=', PaymentStatus::Confirmed)
                    ->update([
                        'amount_cents' => $totalCents,
                        'currency' => $currency,
                    ]);

                $after = $primary->only([
                    'billing_category_id',
                    'qualification_type_id',
                    'fee_structure_id',
                    'is_foreign_snapshot',
                    'processing_days_snapshot',
                    'fee_label_snapshot',
                    'currency',
                    'amount_cents',
                ]);

                $this->audit->record(
                    eventType: 'finance.invoice_updated',
                    module: 'Finance',
                    actionName: 'invoice_updated',
                    message: 'Invoice updated to reflect fee-impacting changes before payment.',
                    entityType: Invoice::class,
                    entityId: $primary->id,
                    beforeState: $before,
                    afterState: $after,
                    metadata: [
                        'application_id' => $application->id,
                        'invoice_number' => $primary->invoice_number,
                    ],
                    actor: $actor,
                );

                $this->lifecycle->event(
                    application: $application,
                    eventType: 'payment',
                    eventCodeBase: 'payment.invoice_updated',
                    stage: LifecycleStage::Payment,
                    title: 'Invoice updated',
                    description: 'Invoice was updated to reflect the latest qualification items before payment.',
                    visibility: LifecycleVisibility::Internal,
                    actor: $actor,
                    metadata: [
                        'invoice_id' => $primary->id,
                        'invoice_number' => $primary->invoice_number,
                    ],
                    occurredAt: now(),
                );

                return $this->resolveInvoiceForPaymentFlow($application, $primary);
            }

            $quotationNumber = $this->generateQuotationNumber();

            $invoice = Invoice::create([
                'application_id' => $application->id,
                'supplementary_of_invoice_id' => null,
                'billing_category_id' => null,
                'qualification_type_id' => null,
                'fee_structure_id' => null,
                'is_foreign_snapshot' => (bool) $application->is_foreign,
                'processing_days_snapshot' => null,
                'fee_label_snapshot' => 'Multiple qualifications',
                'document_type' => InvoiceDocumentType::Quotation,
                'quotation_number' => $quotationNumber,
                'invoice_number' => $quotationNumber,
                'currency' => $currency,
                'amount_cents' => $totalCents,
                'status' => InvoiceStatus::Issued,
                'issued_at' => now(),
                'due_at' => null,
                'expires_at' => now()->addDays(60),
                'paid_at' => null,
                'metadata' => [
                    'generated_by' => 'wizard_payment_step',
                    'invoice_role' => 'primary',
                    'breakdown' => $breakdown,
                ],
            ]);

            if ($application->current_status === ApplicationStatus::Draft) {
                $application->forceFill(['current_status' => ApplicationStatus::PendingPayment])->save();
            }

            $this->audit->record(
                eventType: 'finance.quotation_issued',
                module: 'Finance',
                actionName: 'quotation_issued',
                message: 'Quotation issued for application (fee resolved).',
                entityType: Invoice::class,
                entityId: $invoice->id,
                metadata: [
                    'application_id' => $application->id,
                    'quotation_number' => $invoice->quotation_number,
                    'amount_cents' => $invoice->amount_cents,
                    'currency' => $invoice->currency,
                    'expires_at' => optional($invoice->expires_at)?->toIso8601String(),
                ],
                actor: $actor,
            );

            $this->lifecycle->milestone(
                application: $application,
                eventType: 'payment',
                eventCode: 'payment.quotation_issued',
                stage: LifecycleStage::Payment,
                title: 'Quotation generated',
                description: 'A quotation was generated for this application.',
                visibility: LifecycleVisibility::Both,
                actor: $actor,
                metadata: [
                    'invoice_id' => $invoice->id,
                    'quotation_number' => $invoice->quotation_number,
                    'amount_cents' => $invoice->amount_cents,
                    'currency' => $invoice->currency,
                    'expires_at' => optional($invoice->expires_at)?->toIso8601String(),
                ],
                occurredAt: now(),
            );

            return $this->resolveInvoiceForPaymentFlow($application, $invoice);
        });
    }

    /**
     * Invoice used for initiating payment (open supplementary top-up if balance due; otherwise primary).
     */
    private function resolveInvoiceForPaymentFlow(Application $application, Invoice $primary): Invoice
    {
        $application->refresh()->loadMissing('payments', 'qualifications');

        $outstanding = $this->paymentSatisfaction->outstandingCents($application);
        if ($outstanding > 0) {
            $supplementary = Invoice::query()
                ->where('application_id', $application->id)
                ->whereNotNull('supplementary_of_invoice_id')
                ->where('status', InvoiceStatus::Issued)
                ->whereNull('paid_at')
                ->orderByDesc('id')
                ->first();

            if ($supplementary) {
                return $supplementary;
            }
        }

        return $primary;
    }

    /**
     * Paid primary invoices are immutable. Additional fees are billed on a separate supplementary invoice.
     */
    private function syncSupplementaryForPaidPrimary(
        Invoice $primary,
        Application $application,
        User $actor,
        int $requiredTotalCents,
        string $currency,
        array $breakdown,
    ): void {
        $sumPaid = $this->sumConfirmedPaymentsCents($application);
        $outstanding = max(0, $requiredTotalCents - $sumPaid);

        $amendmentReason = $this->consumePendingAmendmentReason($application);

        if ($outstanding === 0) {
            $this->voidOpenSupplementaryInvoices($application, $primary, $actor);

            if ($sumPaid > $requiredTotalCents) {
                $this->setApplicationOverpaymentNotice($application);
            } else {
                $this->clearApplicationOverpaymentNotice($application);
            }

            return;
        }

        $this->clearApplicationOverpaymentNotice($application);

        $existingOpen = Invoice::query()
            ->where('application_id', $application->id)
            ->where('supplementary_of_invoice_id', $primary->id)
            ->where('status', InvoiceStatus::Issued)
            ->whereNull('paid_at')
            ->lockForUpdate()
            ->first();

        $metaBase = [
            'invoice_role' => 'supplementary',
            'invoice_display_label' => 'Supplementary invoice (top-up)',
            'related_primary_invoice_id' => $primary->id,
            'related_primary_invoice_number' => $primary->invoice_number,
            'required_fee_total_cents_after_amendment' => $requiredTotalCents,
            'credited_confirmed_payments_cents' => $sumPaid,
            'balance_due_cents' => $outstanding,
            'fee_breakdown_after_amendment' => $breakdown,
            'amendment_reason' => $amendmentReason ?? 'Qualification amended; additional verification fee applies.',
            'synced_at' => now()->toIso8601String(),
        ];

        if ($existingOpen) {
            $before = $existingOpen->only(['amount_cents', 'currency', 'metadata']);

            $existingOpen->forceFill([
                'currency' => $currency,
                'amount_cents' => $outstanding,
                'fee_label_snapshot' => 'Supplementary invoice (top-up)',
                'metadata' => array_merge((array) ($existingOpen->metadata ?? []), $metaBase),
            ])->save();

            $this->audit->record(
                eventType: 'finance.supplementary_invoice_updated',
                module: 'Finance',
                actionName: 'supplementary_invoice_updated',
                message: 'Supplementary top-up invoice updated for amended qualification fees.',
                entityType: Invoice::class,
                entityId: $existingOpen->id,
                beforeState: $before,
                afterState: $existingOpen->only(['amount_cents', 'currency', 'metadata']),
                metadata: [
                    'application_id' => $application->id,
                    'primary_invoice_number' => $primary->invoice_number,
                ],
                actor: $actor,
            );

            return;
        }

        $supplementary = Invoice::create([
            'application_id' => $application->id,
            'supplementary_of_invoice_id' => $primary->id,
            'billing_category_id' => null,
            'qualification_type_id' => null,
            'fee_structure_id' => null,
            'is_foreign_snapshot' => (bool) $application->is_foreign,
            'processing_days_snapshot' => null,
            'fee_label_snapshot' => 'Supplementary invoice (top-up)',
            'document_type' => InvoiceDocumentType::Invoice,
            'invoice_number' => $this->generateSupplementaryInvoiceNumber(),
            'currency' => $currency,
            'amount_cents' => $outstanding,
            'status' => InvoiceStatus::Issued,
            'issued_at' => now(),
            'due_at' => null,
            'paid_at' => null,
            'metadata' => $metaBase,
        ]);

        $this->audit->record(
            eventType: 'finance.supplementary_invoice_issued',
            module: 'Finance',
            actionName: 'supplementary_invoice_issued',
            message: 'Supplementary top-up invoice issued for amended qualification fees.',
            entityType: Invoice::class,
            entityId: $supplementary->id,
            metadata: [
                'application_id' => $application->id,
                'primary_invoice_id' => $primary->id,
                'invoice_number' => $supplementary->invoice_number,
                'amount_cents' => $outstanding,
            ],
            actor: $actor,
        );

        $this->lifecycle->event(
            application: $application,
            eventType: 'payment',
            eventCodeBase: 'payment.supplementary_invoice_issued',
            stage: LifecycleStage::Payment,
            title: 'Supplementary invoice issued',
            description: 'A separate top-up invoice was created for the additional fee; the original settled invoice was not changed.',
            visibility: LifecycleVisibility::Both,
            actor: $actor,
            metadata: [
                'invoice_id' => $supplementary->id,
                'invoice_number' => $supplementary->invoice_number,
                'amount_cents' => $outstanding,
            ],
            occurredAt: now(),
        );
    }

    private function voidOpenSupplementaryInvoices(Application $application, Invoice $primary, User $actor): void
    {
        $open = Invoice::query()
            ->where('application_id', $application->id)
            ->where('supplementary_of_invoice_id', $primary->id)
            ->where('status', InvoiceStatus::Issued)
            ->whereNull('paid_at')
            ->get();

        foreach ($open as $invoice) {
            $before = $invoice->only(['status', 'amount_cents']);
            $invoice->forceFill([
                'status' => InvoiceStatus::Void,
                'metadata' => array_merge((array) ($invoice->metadata ?? []), [
                    'voided_at' => now()->toIso8601String(),
                    'void_reason' => 'Fee balance cleared; supplementary invoice no longer required.',
                ]),
            ])->save();

            $this->audit->record(
                eventType: 'finance.supplementary_invoice_voided',
                module: 'Finance',
                actionName: 'supplementary_invoice_voided',
                message: 'Open supplementary invoice voided — outstanding balance is zero.',
                entityType: Invoice::class,
                entityId: $invoice->id,
                beforeState: $before,
                afterState: $invoice->only(['status']),
                metadata: ['application_id' => $application->id],
                actor: $actor,
            );
        }
    }

    private function sumConfirmedPaymentsCents(Application $application): int
    {
        return (int) Payment::query()
            ->where('application_id', $application->id)
            ->where('status', PaymentStatus::Confirmed)
            ->sum('amount_cents');
    }

    private function consumePendingAmendmentReason(Application $application): ?string
    {
        $application->refresh();
        $meta = (array) ($application->metadata ?? []);
        $reason = $meta['pending_invoice_amendment_reason'] ?? null;
        if (! is_string($reason) || trim($reason) === '') {
            return null;
        }

        unset($meta['pending_invoice_amendment_reason']);
        $application->forceFill(['metadata' => $meta])->save();

        return trim($reason);
    }

    private function setApplicationOverpaymentNotice(Application $application): void
    {
        $application->refresh();
        $meta = (array) ($application->metadata ?? []);
        $meta['fee_amendment_overpayment_notice'] = 'This amendment may result in an overpayment. Please contact Finance.';
        $application->forceFill(['metadata' => $meta])->save();
    }

    private function clearApplicationOverpaymentNotice(Application $application): void
    {
        $application->refresh();
        $meta = (array) ($application->metadata ?? []);
        unset($meta['fee_amendment_overpayment_notice']);
        $application->forceFill(['metadata' => $meta])->save();
    }

    private function generateQuotationNumber(): string
    {
        return 'QUO-'.now()->format('Y').'-'.Str::upper(Str::random(10));
    }

    private function generateInvoiceNumber(Application $application): string
    {
        return 'INV-'.now()->format('Y').'-'.Str::upper(Str::random(10));
    }

    private function generateSupplementaryInvoiceNumber(): string
    {
        return 'SUP-'.now()->format('Y').'-'.Str::upper(Str::random(10));
    }
}
