<?php

namespace App\Domain\Payments;

use App\Domain\Audit\AuditLogService;
use App\Domain\Fees\QualificationFeeResolver;
use App\Domain\Tracking\ApplicationLifecycleService;
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
    )
    {
    }

    public function ensureInvoice(Application $application, User $actor): Invoice
    {
        return DB::transaction(function () use ($application, $actor) {
            $existing = Invoice::query()->where('application_id', $application->id)->lockForUpdate()->first();

            $application->loadMissing('qualifications');

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

                // Snapshot fee on each qualification item (used for immutability + audit).
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

            if ($existing) {
                // Invoice immutability: do not mutate settled invoices. For unpaid/issued invoices (before payment),
                // keep Step 2 + Payment consistent by updating the fee snapshot if fee-impacting fields changed.
                if ($existing->status !== InvoiceStatus::Issued || $existing->paid_at) {
                    return $existing;
                }

                $application->loadMissing('payments');
                $hasConfirmedPayment = $application->payments->contains(fn ($p) => $p->status?->value === 'confirmed');
                if ($hasConfirmedPayment) {
                    return $existing;
                }

                $needsUpdate =
                    (int) $existing->amount_cents !== (int) $totalCents
                    || (string) $existing->currency !== (string) $currency;

                if (! $needsUpdate) {
                    return $existing;
                }

                $before = $existing->only([
                    'billing_category_id',
                    'qualification_type_id',
                    'fee_structure_id',
                    'is_foreign_snapshot',
                    'processing_days_snapshot',
                    'fee_label_snapshot',
                    'currency',
                    'amount_cents',
                ]);

                $existing->forceFill([
                    // Multi-qualification invoices store the detailed fee snapshot in metadata.breakdown.
                    'billing_category_id' => null,
                    'qualification_type_id' => null,
                    'fee_structure_id' => null,
                    'is_foreign_snapshot' => (bool) $application->is_foreign,
                    'processing_days_snapshot' => null,
                    'fee_label_snapshot' => 'Multiple qualifications',
                    'currency' => $currency,
                    'amount_cents' => $totalCents,
                    'metadata' => array_merge((array) ($existing->metadata ?? []), [
                        'breakdown' => $breakdown,
                        'recalculated_at' => now()->toIso8601String(),
                    ]),
                ])->save();

                Payment::query()
                    ->where('invoice_id', $existing->id)
                    ->where('status', '!=', PaymentStatus::Confirmed)
                    ->update([
                        'amount_cents' => $totalCents,
                        'currency' => $currency,
                    ]);

                $after = $existing->only([
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
                    entityId: $existing->id,
                    beforeState: $before,
                    afterState: $after,
                    metadata: [
                        'application_id' => $application->id,
                        'invoice_number' => $existing->invoice_number,
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
                        'invoice_id' => $existing->id,
                        'invoice_number' => $existing->invoice_number,
                    ],
                    occurredAt: now(),
                );

                return $existing;
            }

            $invoice = Invoice::create([
                'application_id' => $application->id,
                'billing_category_id' => null,
                'qualification_type_id' => null,
                'fee_structure_id' => null,
                'is_foreign_snapshot' => (bool) $application->is_foreign,
                'processing_days_snapshot' => null,
                'fee_label_snapshot' => 'Multiple qualifications',
                'invoice_number' => $this->generateInvoiceNumber($application),
                'currency' => $currency,
                'amount_cents' => $totalCents,
                'status' => InvoiceStatus::Issued,
                'issued_at' => now(),
                'due_at' => null,
                'paid_at' => null,
                'metadata' => [
                    'generated_by' => 'wizard_payment_step',
                    'breakdown' => $breakdown,
                ],
            ]);

            $this->audit->record(
                eventType: 'finance.invoice_issued',
                module: 'Finance',
                actionName: 'invoice_issued',
                message: 'Invoice issued for application (fee resolved).',
                entityType: Invoice::class,
                entityId: $invoice->id,
                metadata: [
                    'application_id' => $application->id,
                    'invoice_number' => $invoice->invoice_number,
                    'amount_cents' => $invoice->amount_cents,
                    'currency' => $invoice->currency,
                    'billing_category_id' => $invoice->billing_category_id,
                    'qualification_type_id' => $invoice->qualification_type_id,
                    'fee_structure_id' => $invoice->fee_structure_id,
                    'is_foreign' => $invoice->is_foreign_snapshot,
                    'processing_days' => $invoice->processing_days_snapshot,
                ],
                actor: $actor,
            );

            $this->lifecycle->milestone(
                application: $application,
                eventType: 'payment',
                eventCode: 'payment.invoice_issued',
                stage: LifecycleStage::Payment,
                title: 'Invoice generated',
                description: 'An invoice was generated for this application.',
                visibility: LifecycleVisibility::Both,
                actor: $actor,
                metadata: [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'amount_cents' => $invoice->amount_cents,
                    'currency' => $invoice->currency,
                ],
                occurredAt: now(),
            );

            return $invoice;
        });
    }

    private function generateInvoiceNumber(Application $application): string
    {
        return 'INV-'.now()->format('Y').'-'.Str::upper(Str::random(10));
    }
}

