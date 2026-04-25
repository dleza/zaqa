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
            if ($existing) {
                return $existing;
            }

            $application->loadMissing('qualification');

            $qualificationTypeId = (int) ($application->qualification?->qualification_type_id ?? 0);
            if ($qualificationTypeId < 1) {
                throw ValidationException::withMessages([
                    'qualification_type_id' => 'Qualification type must be selected before billing.',
                ]);
            }

            $resolved = $this->fees->resolve($qualificationTypeId, (bool) $application->is_foreign, Carbon::now());

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

