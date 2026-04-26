<?php

namespace App\Domain\Payments;

use App\Domain\Audit\AuditLogService;
use App\Domain\Fees\QualificationFeeResolver;
use App\Domain\Tracking\ApplicationLifecycleService;
use App\Enums\InvoiceStatus;
use App\Enums\LifecycleStage;
use App\Enums\LifecycleVisibility;
use App\Models\Application;
use App\Models\Invoice;
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

            $application->loadMissing('qualification');

            $qualificationTypeId = (int) ($application->qualification?->qualification_type_id ?? 0);
            if ($qualificationTypeId < 1) {
                throw ValidationException::withMessages([
                    'qualification_type_id' => 'Qualification type must be selected before billing.',
                ]);
            }

            $resolved = $this->fees->resolve($qualificationTypeId, (bool) $application->is_foreign, Carbon::now());

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
                    (int) $existing->billing_category_id !== (int) $resolved['billing_category']->id
                    || (int) $existing->qualification_type_id !== (int) $resolved['qualification_type']->id
                    || (int) $existing->fee_structure_id !== (int) $resolved['fee_structure']->id
                    || (bool) $existing->is_foreign_snapshot !== (bool) $application->is_foreign
                    || (int) $existing->amount_cents !== (int) $resolved['fee_cents']
                    || (string) $existing->currency !== (string) $resolved['currency'];

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
                    'billing_category_id' => $resolved['billing_category']->id,
                    'qualification_type_id' => $resolved['qualification_type']->id,
                    'fee_structure_id' => $resolved['fee_structure']->id,
                    'is_foreign_snapshot' => (bool) $application->is_foreign,
                    'processing_days_snapshot' => $resolved['processing_days'],
                    'fee_label_snapshot' => $resolved['billing_category']->name,
                    'currency' => $resolved['currency'],
                    'amount_cents' => $resolved['fee_cents'],
                ])->save();

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
                    description: 'Invoice was updated to reflect the latest qualification and locality details.',
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
                'billing_category_id' => $resolved['billing_category']->id,
                'qualification_type_id' => $resolved['qualification_type']->id,
                'fee_structure_id' => $resolved['fee_structure']->id,
                'is_foreign_snapshot' => (bool) $application->is_foreign,
                'processing_days_snapshot' => $resolved['processing_days'],
                'fee_label_snapshot' => $resolved['billing_category']->name,
                'invoice_number' => $this->generateInvoiceNumber($application),
                'currency' => $resolved['currency'],
                'amount_cents' => $resolved['fee_cents'],
                'status' => InvoiceStatus::Issued,
                'issued_at' => now(),
                'due_at' => null,
                'paid_at' => null,
                'metadata' => [
                    'generated_by' => 'wizard_payment_step',
                    'fee_effective_from' => optional($resolved['fee_structure']->effective_from)?->toIso8601String(),
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

