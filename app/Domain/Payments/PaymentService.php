<?php

namespace App\Domain\Payments;

use App\Domain\Audit\AuditLogService;
use App\Domain\Tracking\ApplicationLifecycleService;
use App\Enums\InvoiceStatus;
use App\Enums\LifecycleStage;
use App\Enums\LifecycleVisibility;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Application;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentWebhookLog;
use App\Models\QualificationDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly InvoiceService $invoices,
        private readonly PaymentGatewayManager $gateways,
        private readonly ApplicationLifecycleService $lifecycle,
    ) {
    }

    public function latestPaymentFor(Application $application): ?Payment
    {
        return Payment::query()
            ->where('application_id', $application->id)
            ->latest('id')
            ->first();
    }

    public function selectMethod(Application $application, PaymentMethod $method, User $actor): Payment
    {
        $invoice = $this->invoices->ensureInvoice($application, $actor);

        return DB::transaction(function () use ($application, $invoice, $method, $actor) {
            $invoice->refresh();
            if ($invoice->status === InvoiceStatus::Paid) {
                $this->audit->record(
                    eventType: 'payments.method_change_blocked',
                    module: 'Finance',
                    actionName: 'method_change_blocked',
                    message: 'Payment method change blocked: invoice already settled.',
                    entityType: Invoice::class,
                    entityId: $invoice->id,
                    metadata: [
                        'application_id' => $application->id,
                        'invoice_id' => $invoice->id,
                        'requested_method' => $method->value,
                    ],
                    actor: $actor,
                );

                throw ValidationException::withMessages([
                    'payment' => 'Payment is already confirmed for this invoice. You cannot change payment method.',
                ]);
            }

            $payment = Payment::create([
                'application_id' => $application->id,
                'invoice_id' => $invoice->id,
                'method' => $method,
                'status' => PaymentStatus::Draft,
                'currency' => $invoice->currency,
                'amount_cents' => $invoice->amount_cents,
                'provider' => 'test',
                'provider_reference' => null,
                'provider_transaction_id' => null,
                'mobile_number' => null,
                'proof_document_id' => null,
                'last_status_at' => now(),
            ]);

            $this->audit->record(
                eventType: 'payments.method_selected',
                module: 'Finance',
                actionName: 'method_selected',
                message: 'Payment method selected.',
                entityType: Payment::class,
                entityId: $payment->id,
                metadata: [
                    'application_id' => $application->id,
                    'invoice_id' => $invoice->id,
                    'method' => $method->value,
                ],
                actor: $actor,
            );

            $this->lifecycle->milestone(
                application: $application,
                eventType: 'payment',
                eventCode: 'payment.method_selected',
                stage: LifecycleStage::Payment,
                title: 'Payment method selected',
                description: 'Applicant selected a payment method.',
                visibility: LifecycleVisibility::Both,
                actor: $actor,
                metadata: [
                    'method' => $method->value,
                    'payment_id' => $payment->id,
                    'invoice_id' => $invoice->id,
                ],
                occurredAt: now(),
            );

            return $payment;
        });
    }

    /**
     * @return array{payment: Payment, redirect_url: string|null}
     */
    public function initiateOnline(Payment $payment, array $payload, User $actor): array
    {
        $payment->loadMissing('invoice');
        if ($payment->invoice && $payment->invoice->status === InvoiceStatus::Paid) {
            $this->audit->record(
                eventType: 'payments.initiation_blocked',
                module: 'Finance',
                actionName: 'initiation_blocked',
                message: 'Payment initiation blocked: invoice already settled.',
                entityType: Payment::class,
                entityId: $payment->id,
                metadata: [
                    'application_id' => $payment->application_id,
                    'invoice_id' => $payment->invoice_id,
                    'method' => $payment->method?->value ?? (string) $payment->method,
                ],
                actor: $actor,
            );

            throw ValidationException::withMessages([
                'payment' => 'Payment is already confirmed for this invoice.',
            ]);
        }

        if (! in_array($payment->method, [PaymentMethod::Card, PaymentMethod::MobileMoney], true)) {
            throw ValidationException::withMessages([
                'method' => 'This payment method does not support online initiation.',
            ]);
        }

        return DB::transaction(function () use ($payment, $payload, $actor) {
            $payment->refresh();
            $gateway = $this->gateways->gateway((string) $payment->provider);

            $result = $gateway->initiate($payment, $payment->method, $payload);

            $payment->forceFill([
                'status' => $payment->method === PaymentMethod::MobileMoney ? PaymentStatus::PendingConfirmation : PaymentStatus::Initiated,
                'provider_reference' => (string) $result['provider_reference'],
                'provider_transaction_id' => $result['provider_transaction_id'] ?: null,
                'initiated_at' => now(),
                'last_status_at' => now(),
                'raw_payload' => $result['raw_payload'] ?? null,
            ])->save();

            $this->audit->record(
                eventType: $payment->method === PaymentMethod::MobileMoney ? 'payments.mobile_money_initiated' : 'payments.card_initiated',
                module: 'Finance',
                actionName: 'payment_initiated',
                message: 'Online payment initiated.',
                entityType: Payment::class,
                entityId: $payment->id,
                metadata: [
                    'application_id' => $payment->application_id,
                    'method' => $payment->method->value,
                    'provider' => $payment->provider,
                    'provider_reference' => $payment->provider_reference,
                ],
                actor: $actor,
            );

            $this->lifecycle->event(
                application: $payment->application()->firstOrFail(),
                eventType: 'payment',
                eventCodeBase: $payment->method === PaymentMethod::MobileMoney ? 'payment.mobile_money_initiated' : 'payment.card_initiated',
                stage: LifecycleStage::Payment,
                title: 'Payment initiated',
                description: $payment->method === PaymentMethod::MobileMoney
                    ? 'Mobile Money payment initiated.'
                    : 'Card payment initiated.',
                visibility: LifecycleVisibility::Both,
                actor: $actor,
                metadata: [
                    'payment_id' => $payment->id,
                    'method' => $payment->method->value,
                    'provider_reference' => $payment->provider_reference,
                ],
                occurredAt: now(),
            );

            return [
                'payment' => $payment,
                'redirect_url' => $result['redirect_url'] ?? null,
            ];
        });
    }

    public function attachProof(Payment $payment, QualificationDocument $document, User $actor): Payment
    {
        $payment->loadMissing('invoice');
        if ($payment->invoice && $payment->invoice->status === InvoiceStatus::Paid) {
            $this->audit->record(
                eventType: 'payments.proof_upload_blocked',
                module: 'Finance',
                actionName: 'proof_upload_blocked',
                message: 'Proof upload blocked: invoice already settled.',
                entityType: Payment::class,
                entityId: $payment->id,
                metadata: [
                    'application_id' => $payment->application_id,
                    'invoice_id' => $payment->invoice_id,
                ],
                actor: $actor,
            );

            throw ValidationException::withMessages([
                'payment' => 'Payment is already confirmed for this invoice.',
            ]);
        }

        if (! in_array($payment->method, [PaymentMethod::BankDeposit, PaymentMethod::BankTransfer], true)) {
            throw ValidationException::withMessages([
                'method' => 'Proof upload is only available for bank deposit/transfer.',
            ]);
        }

        return DB::transaction(function () use ($payment, $document, $actor) {
            $payment->forceFill([
                'proof_document_id' => $document->id,
                'status' => PaymentStatus::AwaitingFinanceReview,
                'awaiting_finance_review_at' => now(),
                'last_status_at' => now(),
            ])->save();

            $this->audit->record(
                eventType: 'payments.proof_uploaded',
                module: 'Finance',
                actionName: 'proof_uploaded',
                message: 'Proof of payment uploaded.',
                entityType: Payment::class,
                entityId: $payment->id,
                metadata: [
                    'application_id' => $payment->application_id,
                    'document_id' => $document->id,
                ],
                actor: $actor,
            );

            $this->lifecycle->milestone(
                application: $payment->application()->firstOrFail(),
                eventType: 'payment',
                eventCode: 'payment.manual_proof_uploaded',
                stage: LifecycleStage::Payment,
                title: 'Proof of payment uploaded',
                description: 'Proof uploaded for finance review.',
                visibility: LifecycleVisibility::Both,
                actor: $actor,
                metadata: [
                    'payment_id' => $payment->id,
                    'document_id' => $document->id,
                ],
                occurredAt: now(),
            );

            return $payment;
        });
    }

    public function financeApprove(Payment $payment, User $actor, ?string $comment = null): Payment
    {
        return DB::transaction(function () use ($payment, $actor, $comment) {
            $payment->forceFill([
                'status' => PaymentStatus::Confirmed,
                'confirmed_at' => now(),
                'reviewed_by_user_id' => $actor->id,
                'reviewed_at' => now(),
                'review_comment' => $comment,
                'last_status_at' => now(),
            ])->save();

            $this->markApplicationPaid($payment);

            $this->audit->record(
                eventType: 'finance.payment_approved',
                module: 'Finance',
                actionName: 'payment_approved',
                message: 'Manual payment approved by finance.',
                entityType: Payment::class,
                entityId: $payment->id,
                metadata: [
                    'application_id' => $payment->application_id,
                ],
                actor: $actor,
            );

            $this->lifecycle->milestone(
                application: $payment->application()->firstOrFail(),
                eventType: 'finance',
                eventCode: 'payment.finance_approved',
                stage: LifecycleStage::Payment,
                title: 'Payment confirmed',
                description: 'Finance approved the manual payment proof.',
                visibility: LifecycleVisibility::Both,
                actor: $actor,
                comment: $comment,
                metadata: [
                    'payment_id' => $payment->id,
                ],
                occurredAt: now(),
            );

            return $payment;
        });
    }

    public function financeReject(Payment $payment, User $actor, string $reason): Payment
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'Rejection reason is required.',
            ]);
        }

        return DB::transaction(function () use ($payment, $actor, $reason) {
            $payment->forceFill([
                'status' => PaymentStatus::Rejected,
                'rejected_at' => now(),
                'reviewed_by_user_id' => $actor->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
                'last_status_at' => now(),
            ])->save();

            $this->audit->record(
                eventType: 'finance.payment_rejected',
                module: 'Finance',
                actionName: 'payment_rejected',
                message: 'Manual payment rejected by finance.',
                entityType: Payment::class,
                entityId: $payment->id,
                metadata: [
                    'application_id' => $payment->application_id,
                ],
                actor: $actor,
            );

            $this->lifecycle->milestone(
                application: $payment->application()->firstOrFail(),
                eventType: 'finance',
                eventCode: 'payment.finance_rejected',
                stage: LifecycleStage::Payment,
                title: 'Payment rejected',
                description: 'Finance rejected the manual payment proof.',
                visibility: LifecycleVisibility::Both,
                actor: $actor,
                comment: $reason,
                metadata: [
                    'payment_id' => $payment->id,
                    'reason' => $reason,
                ],
                occurredAt: now(),
            );

            return $payment;
        });
    }

    public function handleGatewayReturn(Payment $payment, array $payload): Payment
    {
        $gateway = $this->gateways->gateway((string) $payment->provider);
        $verified = $gateway->verify((string) $payment->provider_reference, $payload);

        $this->logWebhookLikeEvent($payment, 'return', $payload);

        return $this->applyVerifiedStatus($payment, $verified['status'] ?? 'failed', $verified);
    }

    public function handleGatewayWebhook(string $provider, array $payload): PaymentWebhookLog
    {
        $log = PaymentWebhookLog::create([
            'provider' => $provider,
            'event_type' => (string) ($payload['event_type'] ?? 'webhook'),
            'provider_reference' => (string) ($payload['ref'] ?? null),
            'provider_transaction_id' => (string) ($payload['tx'] ?? null),
            'application_id' => null,
            'payment_id' => null,
            'payload' => $payload,
            'signature_valid' => null,
            'received_at' => now(),
            'processed_at' => null,
            'process_status' => 'received',
            'error_message' => null,
        ]);

        return $log;
    }

    private function applyVerifiedStatus(Payment $payment, string $status, array $verified): Payment
    {
        return DB::transaction(function () use ($payment, $status, $verified) {
            $payment->refresh();

            if ($status === 'confirmed') {
                $payment->forceFill([
                    'status' => PaymentStatus::Confirmed,
                    'confirmed_at' => $payment->confirmed_at ?? now(),
                    'provider_transaction_id' => $verified['provider_transaction_id'] ?? $payment->provider_transaction_id,
                    'raw_payload' => $verified['raw_payload'] ?? $payment->raw_payload,
                    'last_status_at' => now(),
                ])->save();

                $this->markApplicationPaid($payment);

                $this->lifecycle->milestone(
                    application: $payment->application()->firstOrFail(),
                    eventType: 'payment',
                    eventCode: 'payment.confirmed',
                    stage: LifecycleStage::Payment,
                    title: 'Payment confirmed',
                    description: 'Payment was confirmed by the payment provider.',
                    visibility: LifecycleVisibility::Both,
                    actor: null,
                    metadata: [
                        'payment_id' => $payment->id,
                        'method' => $payment->method?->value ?? (string) $payment->method,
                        'provider' => $payment->provider,
                        'provider_reference' => $payment->provider_reference,
                    ],
                    occurredAt: now(),
                );

                return $payment;
            }

            $payment->forceFill([
                'status' => PaymentStatus::Failed,
                'failed_at' => now(),
                'raw_payload' => $verified['raw_payload'] ?? $payment->raw_payload,
                'last_status_at' => now(),
            ])->save();

            $this->lifecycle->milestone(
                application: $payment->application()->firstOrFail(),
                eventType: 'payment',
                eventCode: 'payment.failed',
                stage: LifecycleStage::Payment,
                title: 'Payment failed',
                description: 'Payment could not be confirmed.',
                visibility: LifecycleVisibility::Both,
                actor: null,
                metadata: [
                    'payment_id' => $payment->id,
                    'method' => $payment->method?->value ?? (string) $payment->method,
                    'provider' => $payment->provider,
                    'provider_reference' => $payment->provider_reference,
                ],
                occurredAt: now(),
            );

            return $payment;
        });
    }

    private function markApplicationPaid(Payment $payment): void
    {
        $application = $payment->application()->lockForUpdate()->first();
        if (! $application) {
            return;
        }

        if (! $application->paid_at) {
            $application->forceFill(['paid_at' => now()])->save();
        }

        $invoice = $payment->invoice;
        if ($invoice && $invoice->status !== InvoiceStatus::Paid) {
            $invoice->forceFill([
                'status' => InvoiceStatus::Paid,
                'paid_at' => $invoice->paid_at ?? now(),
            ])->save();
        }
    }

    private function logWebhookLikeEvent(Payment $payment, string $eventType, array $payload): void
    {
        PaymentWebhookLog::create([
            'provider' => (string) $payment->provider,
            'event_type' => $eventType,
            'provider_reference' => (string) $payment->provider_reference,
            'provider_transaction_id' => (string) ($payload['tx'] ?? null),
            'application_id' => $payment->application_id,
            'payment_id' => $payment->id,
            'payload' => $payload,
            'signature_valid' => null,
            'received_at' => now(),
            'processed_at' => now(),
            'process_status' => 'processed',
            'error_message' => null,
        ]);
    }
}

