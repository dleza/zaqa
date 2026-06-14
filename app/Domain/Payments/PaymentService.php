<?php

namespace App\Domain\Payments;

use App\Domain\Applications\ApplicationAutoSubmissionService;
use App\Domain\Applications\ApplicationSubmissionReadinessService;
use App\Domain\Audit\AuditLogService;
use App\Domain\Finance\Events\PaymentProofSubmitted;
use App\Domain\Tracking\ApplicationLifecycleService;
use App\Enums\ApplicationStatus;
use App\Enums\InvoiceStatus;
use App\Enums\LifecycleStage;
use App\Enums\LifecycleVisibility;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Application;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentWebhookLog;
use App\Models\QualificationDocument;
use App\Models\User;
use App\Support\Phone\ZambiaMsisdnNormalizer;
use App\Jobs\Payments\DispatchMobileMoneyPaymentPromptJob;
use App\Jobs\Payments\QueryCGratePaymentAttemptJob;
use App\Support\Payments\PaymentQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Enums\PaymentAttemptStatus;

class PaymentService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly InvoiceService $invoices,
        private readonly PaymentGatewayManager $gateways,
        private readonly ApplicationLifecycleService $lifecycle,
        private readonly ApplicationSubmissionReadinessService $submissionReadiness,
        private readonly ApplicationAutoSubmissionService $autoSubmission,
    ) {
    }

    public function latestPaymentFor(Application $application): ?Payment
    {
        return Payment::query()
            ->where('application_id', $application->id)
            ->latest('id')
            ->first();
    }

    public function rememberSelectedMethod(Application $application, PaymentMethod $method, User $actor): void
    {
        $this->assertApplicationNotLockedByPendingProofReview($application);

        // Persist the choice for UX (tabs), but don't create a payment attempt.
        // A Payment row should be created only when the applicant actually initiates a payment or uploads proof.
        $this->invoices->ensureInvoice($application, $actor);

        DB::transaction(function () use ($application, $method, $actor) {
            $lockedApplication = Application::query()
                ->whereKey($application->id)
                ->lockForUpdate()
                ->firstOrFail();

            $metadata = (array) ($lockedApplication->metadata ?? []);
            $metadata['preferred_payment_method'] = $method->value;
            $lockedApplication->forceFill(['metadata' => $metadata])->save();

            $this->audit->record(
                eventType: 'payments.method_preference_updated',
                module: 'Finance',
                actionName: 'method_preference_updated',
                message: 'Payment method preference updated.',
                entityType: Application::class,
                entityId: $lockedApplication->id,
                metadata: [
                    'application_id' => $lockedApplication->id,
                    'method' => $method->value,
                ],
                actor: $actor,
            );

            $this->lifecycle->milestone(
                application: $lockedApplication,
                eventType: 'payment',
                eventCode: 'payment.method_preference_updated',
                stage: LifecycleStage::Payment,
                title: 'Payment method selected',
                description: 'Applicant selected a payment method (preference only).',
                visibility: LifecycleVisibility::Both,
                actor: $actor,
                metadata: [
                    'method' => $method->value,
                ],
                occurredAt: now(),
            );
        });
    }

    public function createDraftPayment(Application $application, PaymentMethod $method, User $actor): Payment
    {
        $this->assertApplicationNotLockedByPendingProofReview($application);

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

            $existingDraft = Payment::query()
                ->where('application_id', $application->id)
                ->where('invoice_id', $invoice->id)
                ->where('method', $method)
                ->where('status', PaymentStatus::Draft)
                ->latest('id')
                ->first();

            if ($existingDraft) {
                if ($method === PaymentMethod::MobileMoney && $existingDraft->provider !== 'cgrate') {
                    $existingDraft->forceFill(['provider' => 'cgrate'])->save();
                }

                return $existingDraft;
            }

            $payment = Payment::create([
                'application_id' => $application->id,
                'invoice_id' => $invoice->id,
                'method' => $method,
                'status' => PaymentStatus::Draft,
                'currency' => $invoice->currency,
                'amount_cents' => $invoice->amount_cents,
                'provider' => $method === PaymentMethod::MobileMoney ? 'cgrate' : 'test',
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

    public function paymentForManualProofUpload(Application $application, User $actor): Payment
    {
        $this->assertApplicationNotLockedByPendingProofReview($application);

        $invoice = $this->invoices->ensureInvoice($application, $actor);

        return DB::transaction(function () use ($application, $invoice, $actor) {
            $invoice->refresh();
            if ($invoice->status === InvoiceStatus::Paid) {
                throw ValidationException::withMessages([
                    'payment' => 'Payment is already confirmed for this invoice.',
                ]);
            }

            $existing = Payment::query()
                ->where('application_id', $application->id)
                ->where('invoice_id', $invoice->id)
                ->whereIn('method', [PaymentMethod::BankDeposit, PaymentMethod::BankTransfer])
                ->where('status', '!=', PaymentStatus::Confirmed)
                ->latest('id')
                ->first();

            if ($existing) {
                return $existing;
            }

            $payment = Payment::create([
                'application_id' => $application->id,
                'invoice_id' => $invoice->id,
                'method' => PaymentMethod::BankTransfer,
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
                    'method' => PaymentMethod::BankTransfer->value,
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
                    'method' => PaymentMethod::BankTransfer->value,
                    'payment_id' => $payment->id,
                    'invoice_id' => $invoice->id,
                ],
                occurredAt: now(),
            );

            return $payment;
        });
    }

    /**
     * @return array{payment: Payment, redirect_url: string|null, attempt_id?: int|null, already_pending?: bool}
     */
    public function initiateOnline(Payment $payment, array $payload, User $actor): array
    {
        $application = $payment->application()->firstOrFail();
        $this->assertApplicationNotLockedByPendingProofReview($application);
        $this->submissionReadiness->assertReadyForPayment($application, $actor);

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

        return $payment->method === PaymentMethod::MobileMoney
            ? $this->initiateCGrateMobileMoney($payment, $payload, $actor)
            : $this->initiateCardPayment($payment, $payload, $actor);
    }

    /**
     * @return array{payment: Payment, redirect_url: string|null}
     */
    private function initiateCardPayment(Payment $payment, array $payload, User $actor): array
    {
        return DB::transaction(function () use ($payment, $payload, $actor) {
            $payment->refresh();
            $gateway = $this->gateways->gateway((string) $payment->provider);

            $result = $gateway->initiate($payment, $payment->method, $payload);

            $payment->forceFill([
                'status' => PaymentStatus::Initiated,
                'provider_reference' => (string) $result['provider_reference'],
                'provider_transaction_id' => $result['provider_transaction_id'] ?: null,
                'initiated_at' => now(),
                'last_status_at' => now(),
                'raw_payload' => $result['raw_payload'] ?? null,
            ])->save();

            $this->audit->record(
                eventType: 'payments.card_initiated',
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
                eventCodeBase: 'payment.card_initiated',
                stage: LifecycleStage::Payment,
                title: 'Payment initiated',
                description: 'Card payment initiated.',
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

    /**
     * cGrate Mobile Money flow (push + poll):
     * - create PaymentAttempt (idempotent per invoice while pending)
     * - send processCustomerPayment
     * - mark attempt pending/failed based on response code (do not confirm here)
     *
     * @return array{payment: Payment, redirect_url: string|null}
     */
    private function initiateCGrateMobileMoney(Payment $payment, array $payload, User $actor): array
    {
        if (! (bool) config('cgrate.enabled')) {
            throw ValidationException::withMessages([
                'payment' => 'Mobile Money is temporarily unavailable. Please try again later.',
            ]);
        }

        $mobileRaw = trim((string) ($payload['mobile_number'] ?? ''));
        if ($mobileRaw === '') {
            throw ValidationException::withMessages([
                'mobile_number' => 'Mobile number is required.',
            ]);
        }

        try {
            $msisdn = ZambiaMsisdnNormalizer::normalizeForCGrate($mobileRaw, (string) config('cgrate.msisdn_format', 'local'));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'mobile_number' => $e->getMessage(),
            ]);
        }

        $pollInterval = (int) config('cgrate.poll_interval_seconds', 10);
        $expiryMinutes = (int) config('cgrate.payment_expiry_minutes', 10);
        $expiryCutoff = now()->subMinutes($expiryMinutes);

        $attemptMeta = DB::transaction(function () use ($payment, $msisdn, $pollInterval, $expiryCutoff) {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            $existing = PaymentAttempt::query()
                ->where('gateway', 'cgrate')
                ->where('invoice_id', $locked->invoice_id)
                ->whereIn('status', [PaymentAttemptStatus::Initiated, PaymentAttemptStatus::Pending])
                ->whereNull('expired_at')
                ->where(function ($q) use ($expiryCutoff) {
                    $q->whereNull('initiated_at')->orWhere('initiated_at', '>=', $expiryCutoff);
                })
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $existing->forceFill([
                    'next_query_at' => now(),
                ])->save();

                $locked->forceFill([
                    'provider' => 'cgrate',
                    'provider_reference' => $existing->payment_reference,
                    'provider_transaction_id' => $existing->provider_transaction_id,
                    'mobile_number' => $existing->mobile_number,
                    'status' => PaymentStatus::PendingConfirmation,
                    'initiated_at' => $locked->initiated_at ?? $existing->initiated_at ?? now(),
                    'last_status_at' => now(),
                ])->save();

                return [
                    'attempt_id' => (int) $existing->id,
                    'reused' => true,
                ];
            }

            $paymentReference = $this->generateCGratePaymentReference($locked);

            $attempt = PaymentAttempt::create([
                'payment_id' => $locked->id,
                'invoice_id' => $locked->invoice_id,
                'application_id' => $locked->application_id,
                'gateway' => 'cgrate',
                'method' => 'mobile_money',
                'payment_reference' => $paymentReference,
                'provider_transaction_id' => null,
                'mobile_number' => $msisdn,
                'currency' => (string) ($locked->currency ?? config('cgrate.default_currency', 'ZMW')),
                'amount_cents' => (int) $locked->amount_cents,
                'status' => PaymentAttemptStatus::Initiated,
                'gateway_status' => null,
                'response_code' => null,
                'response_message' => null,
                'initiated_at' => now(),
                'next_query_at' => now(),
                'query_attempts' => 0,
                'request_payload' => [
                    'operation' => 'processCustomerPayment',
                    'transactionAmount_mode' => (string) config('cgrate.amount_mode', 'kwacha_decimal'),
                ],
                'metadata' => [
                    'msisdn_format' => (string) config('cgrate.msisdn_format', 'local'),
                ],
            ]);

            $locked->forceFill([
                'provider' => 'cgrate',
                'provider_reference' => $paymentReference,
                'provider_transaction_id' => null,
                'mobile_number' => $msisdn,
                'status' => PaymentStatus::PendingConfirmation,
                'initiated_at' => now(),
                'last_status_at' => now(),
            ])->save();

            return [
                'attempt_id' => (int) $attempt->id,
                'reused' => false,
            ];
        });

        $attemptId = (int) ($attemptMeta['attempt_id'] ?? 0);
        $reused = (bool) ($attemptMeta['reused'] ?? false);

        if ($reused) {
            if ((string) config('queue.default') !== 'sync') {
                QueryCGratePaymentAttemptJob::dispatch($attemptId)
                    ->onQueue(PaymentQueue::polling())
                    ->delay(now()->addSeconds(max(1, $pollInterval)));
            }
        } else {
            DispatchMobileMoneyPaymentPromptJob::dispatch($attemptId);
        }

        $payment->refresh();

        $this->audit->record(
            eventType: 'payments.mobile_money_initiated',
            module: 'Finance',
            actionName: 'payment_initiated',
            message: 'Mobile Money payment initiated.',
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
            eventCodeBase: 'payment.mobile_money_initiated',
            stage: LifecycleStage::Payment,
            title: 'Payment initiated',
            description: 'Mobile Money payment initiated.',
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
            'redirect_url' => null,
            'attempt_id' => $attemptId,
            'already_pending' => $reused,
        ];
    }

    /**
     * Process an inbound cGrate callback by reference. Idempotent and routes through polling confirmation.
     *
     * @param  array<string, mixed>  $payload
     */
    public function processCGrateCallback(string $paymentReference, array $payload): bool
    {
        if (! (bool) config('cgrate.enabled')) {
            return false;
        }

        $attempt = PaymentAttempt::query()
            ->with('payment.invoice')
            ->where('gateway', 'cgrate')
            ->where('payment_reference', $paymentReference)
            ->latest('id')
            ->first();

        if (! $attempt || ! $attempt->payment) {
            return false;
        }

        $this->logWebhookLikeEvent($attempt->payment, 'cgrate.callback', [
            'ref' => $paymentReference,
            'payload' => $this->sanitizeCallbackPayload($payload),
        ]);

        if ($attempt->status?->isTerminal() || $attempt->payment->status === PaymentStatus::Confirmed) {
            return true;
        }

        if ((string) config('queue.default') !== 'sync') {
            QueryCGratePaymentAttemptJob::dispatch((int) $attempt->id)
                ->onQueue(PaymentQueue::high());
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitizeCallbackPayload(array $payload): array
    {
        unset($payload['password'], $payload['username'], $payload['token']);

        return $payload;
    }

    private function generateCGratePaymentReference(Payment $payment): string
    {
        $invoiceId = (int) ($payment->invoice_id ?? 0);
        $paymentId = (int) $payment->id;

        $rand = Str::upper(Str::random(10));

        return 'ZAQA-'.$invoiceId.'-'.$paymentId.'-'.$rand;
    }

    public function attachProof(Payment $payment, QualificationDocument $document, User $actor): Payment
    {
        $application = $payment->application()->firstOrFail();
        $this->submissionReadiness->assertReadyForPayment($application, $actor);
        $this->assertApplicationNotLockedByPendingProofReview($application, $payment);

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

        if ($payment->status === PaymentStatus::AwaitingFinanceReview) {
            throw ValidationException::withMessages([
                'payment' => 'Proof already submitted. Wait for finance review before making changes.',
            ]);
        }

        $updated = DB::transaction(function () use ($payment, $document, $actor) {
            $payment->forceFill([
                'proof_document_id' => $document->id,
                'status' => PaymentStatus::AwaitingFinanceReview,
                'awaiting_finance_review_at' => now(),
                'reviewed_by_user_id' => null,
                'reviewed_at' => null,
                'review_comment' => null,
                'rejection_reason' => null,
                'rejected_at' => null,
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

        $updated->loadMissing(['application.applicant', 'invoice', 'proofDocument']);
        event(new PaymentProofSubmitted($updated, $actor));

        return $updated;
    }

    public function financeApprove(Payment $payment, User $actor, ?string $comment = null): Payment
    {
        $application = $payment->application()->firstOrFail();
        $this->submissionReadiness->assertReadyForPayment($application, $actor);

        return DB::transaction(function () use ($payment, $actor, $comment) {
            $payment = Payment::query()
                ->with(['application', 'invoice'])
                ->lockForUpdate()
                ->findOrFail($payment->id);

            $before = $this->paymentAuditSnapshot($payment);

            $payment->forceFill([
                'status' => PaymentStatus::Confirmed,
                'confirmed_at' => now(),
                'reviewed_by_user_id' => $actor->id,
                'reviewed_at' => now(),
                'review_comment' => $comment,
                'last_status_at' => now(),
            ])->save();

            $this->markApplicationPaid($payment, $actor);
            $payment->refresh()->loadMissing(['application', 'invoice']);
            $after = $this->paymentAuditSnapshot($payment);

            $this->audit->record(
                eventType: 'finance.payment_approved',
                module: 'Finance',
                actionName: 'payment_approved',
                message: 'Manual payment approved by finance.',
                entityType: Payment::class,
                entityId: $payment->id,
                beforeState: $before,
                afterState: $after,
                metadata: [
                    'application_id' => $payment->application_id,
                    'invoice_id' => $payment->invoice_id,
                    'comment' => $comment,
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
            $payment = Payment::query()
                ->with(['application', 'invoice'])
                ->lockForUpdate()
                ->findOrFail($payment->id);

            $before = $this->paymentAuditSnapshot($payment);

            $payment->forceFill([
                'status' => PaymentStatus::Rejected,
                'rejected_at' => now(),
                'reviewed_by_user_id' => $actor->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
                'last_status_at' => now(),
            ])->save();
            $payment->refresh()->loadMissing(['application', 'invoice']);
            $after = $this->paymentAuditSnapshot($payment);

            $this->audit->record(
                eventType: 'finance.payment_rejected',
                module: 'Finance',
                actionName: 'payment_rejected',
                message: 'Manual payment rejected by finance.',
                entityType: Payment::class,
                entityId: $payment->id,
                beforeState: $before,
                afterState: $after,
                metadata: [
                    'application_id' => $payment->application_id,
                    'invoice_id' => $payment->invoice_id,
                    'reason' => $reason,
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

    public function financeCorrect(
        Payment $payment,
        PaymentStatus $targetStatus,
        User $actor,
        string $note,
        ?string $providerTransactionId = null,
    ): Payment {
        $note = trim($note);
        if ($note === '') {
            throw ValidationException::withMessages([
                'note' => 'A correction note is required.',
            ]);
        }

        return DB::transaction(function () use ($payment, $targetStatus, $actor, $note, $providerTransactionId) {
            $payment = Payment::query()
                ->with(['application', 'invoice'])
                ->lockForUpdate()
                ->findOrFail($payment->id);

            $application = $payment->application()->firstOrFail();
            if ($targetStatus === PaymentStatus::Confirmed) {
                $this->submissionReadiness->assertReadyForPayment($application, $actor);
            }

            $this->assertFinanceCorrectionAllowed($payment, $targetStatus);

            $normalizedProviderTransactionId = $providerTransactionId !== null
                ? trim($providerTransactionId)
                : null;
            if ($targetStatus === PaymentStatus::Confirmed && $normalizedProviderTransactionId === null) {
                throw ValidationException::withMessages([
                    'provider_transaction_id' => 'Provider transaction ID is required when confirming a payment.',
                ]);
            }

            $statusChanged = $payment->status !== $targetStatus;
            $providerTransactionChanged = $normalizedProviderTransactionId !== null
                && $normalizedProviderTransactionId !== (string) ($payment->provider_transaction_id ?? '');
            $requiresConfirmedStateSync = $targetStatus === PaymentStatus::Confirmed
                && $this->confirmedPaymentNeedsStateSync($payment);

            if (! $statusChanged && ! $providerTransactionChanged && ! $requiresConfirmedStateSync) {
                throw ValidationException::withMessages([
                    'payment' => 'Change the status or transaction ID before saving a correction.',
                ]);
            }

            $before = $this->paymentAuditSnapshot($payment);

            $updates = [
                'status' => $targetStatus,
                'last_status_at' => now(),
            ];

            if ($normalizedProviderTransactionId !== null) {
                $updates['provider_transaction_id'] = $normalizedProviderTransactionId;
            }

            if ($targetStatus === PaymentStatus::Confirmed) {
                $updates['confirmed_at'] = $payment->confirmed_at ?? now();
                $updates['rejection_reason'] = null;
            } elseif ($targetStatus === PaymentStatus::Rejected) {
                $updates['rejected_at'] = $payment->rejected_at ?? now();
            } elseif ($targetStatus === PaymentStatus::Failed) {
                $updates['failed_at'] = $payment->failed_at ?? now();
                $updates['rejection_reason'] = null;
            } elseif ($targetStatus === PaymentStatus::Expired) {
                $updates['expires_at'] = $payment->expires_at ?? now();
                $updates['rejection_reason'] = null;
            } elseif ($targetStatus === PaymentStatus::PendingConfirmation) {
                $updates['rejection_reason'] = null;
            }

            $payment->forceFill($updates)->save();

            if ($targetStatus === PaymentStatus::Confirmed) {
                $this->markApplicationPaid($payment, $actor);
            }

            $payment->refresh()->loadMissing(['application', 'invoice']);
            $after = $this->paymentAuditSnapshot($payment);

            $this->audit->record(
                eventType: 'finance.payment_corrected',
                module: 'Finance',
                actionName: 'payment_corrected',
                message: $statusChanged
                    ? 'Finance manually corrected a payment status.'
                    : ($providerTransactionChanged
                        ? 'Finance manually corrected a payment reference.'
                        : 'Finance manually synchronized a confirmed payment.'),
                entityType: Payment::class,
                entityId: $payment->id,
                beforeState: $before,
                afterState: $after,
                metadata: [
                    'application_id' => $payment->application_id,
                    'invoice_id' => $payment->invoice_id,
                    'note' => $note,
                    'from_status' => $before['status'] ?? null,
                    'to_status' => $after['status'] ?? null,
                    'status_changed' => $statusChanged,
                    'provider_transaction_id_changed' => $providerTransactionChanged,
                    'application_sync_performed' => $requiresConfirmedStateSync,
                ],
                actor: $actor,
            );

            $this->lifecycle->event(
                application: $payment->application()->firstOrFail(),
                eventType: 'finance',
                eventCodeBase: 'payment.finance_corrected.p'.$payment->id,
                stage: LifecycleStage::Payment,
                title: $statusChanged ? 'Payment manually corrected' : 'Payment reference corrected',
                description: $statusChanged
                    ? 'Finance manually corrected the recorded payment status.'
                    : ($providerTransactionChanged
                        ? 'Finance manually corrected the recorded transaction reference.'
                        : 'Finance manually synchronized the application after confirmed payment.'),
                visibility: LifecycleVisibility::Internal,
                actor: $actor,
                comment: $note,
                metadata: [
                    'payment_id' => $payment->id,
                    'from_status' => $before['status'] ?? null,
                    'to_status' => $after['status'] ?? null,
                    'provider_transaction_id' => $payment->provider_transaction_id,
                    'status_changed' => $statusChanged,
                    'provider_transaction_id_changed' => $providerTransactionChanged,
                    'application_sync_performed' => $requiresConfirmedStateSync,
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

    /**
     * Apply a normalized gateway verification result without calling the gateway again.
     *
     * @param array{provider_transaction_id?: string|null, raw_payload?: array<string,mixed>|null} $verified
     */
    public function applyGatewayVerificationResult(Payment $payment, string $status, array $verified, string $eventType = 'gateway.status'): Payment
    {
        $this->logWebhookLikeEvent($payment, $eventType, [
            'status' => $status,
            'ref' => (string) $payment->provider_reference,
            'tx' => (string) ($verified['provider_transaction_id'] ?? null),
            'raw_payload' => $verified['raw_payload'] ?? null,
        ]);

        return $this->applyVerifiedStatus($payment, $status, $verified);
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

            if ($payment->status === PaymentStatus::Confirmed) {
                return $payment;
            }

            if ($status === 'confirmed') {
                $payment->forceFill([
                    'status' => PaymentStatus::Confirmed,
                    'confirmed_at' => $payment->confirmed_at ?? now(),
                    'provider_transaction_id' => $verified['provider_transaction_id'] ?? $payment->provider_transaction_id,
                    'raw_payload' => $verified['raw_payload'] ?? $payment->raw_payload,
                    'last_status_at' => now(),
                ])->save();

                $this->markApplicationPaid($payment, null);

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

            if (in_array($status, ['pending', 'unknown'], true)) {
                $payment->forceFill([
                    'status' => PaymentStatus::PendingConfirmation,
                    'provider_transaction_id' => $verified['provider_transaction_id'] ?? $payment->provider_transaction_id,
                    'raw_payload' => $verified['raw_payload'] ?? $payment->raw_payload,
                    'last_status_at' => now(),
                ])->save();

                return $payment;
            }

            if ($status === 'rejected') {
                $payment->forceFill([
                    'status' => PaymentStatus::Rejected,
                    'rejected_at' => $payment->rejected_at ?? now(),
                    'raw_payload' => $verified['raw_payload'] ?? $payment->raw_payload,
                    'last_status_at' => now(),
                ])->save();

                $this->lifecycle->milestone(
                    application: $payment->application()->firstOrFail(),
                    eventType: 'payment',
                    eventCode: 'payment.rejected',
                    stage: LifecycleStage::Payment,
                    title: 'Payment rejected',
                    description: 'Payment was rejected by the customer or provider.',
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

            if ($status === 'expired') {
                $payment->forceFill([
                    'status' => PaymentStatus::Expired,
                    'expires_at' => $payment->expires_at ?? now(),
                    'raw_payload' => $verified['raw_payload'] ?? $payment->raw_payload,
                    'last_status_at' => now(),
                ])->save();

                $this->lifecycle->milestone(
                    application: $payment->application()->firstOrFail(),
                    eventType: 'payment',
                    eventCode: 'payment.expired',
                    stage: LifecycleStage::Payment,
                    title: 'Payment expired',
                    description: 'Payment was not confirmed within the allowed time.',
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
                'failed_at' => $payment->failed_at ?? now(),
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

    private function markApplicationPaid(Payment $payment, ?User $actor): void
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

        // Payment satisfaction is the submission trigger (idempotent).
        $this->autoSubmission->submitAfterPaymentSatisfied($application, $payment, $actor);
    }

    private function assertFinanceCorrectionAllowed(Payment $payment, PaymentStatus $targetStatus): void
    {
        if ($payment->status === PaymentStatus::AwaitingFinanceReview) {
            throw ValidationException::withMessages([
                'status' => 'Use the payment proof review workflow while this payment is awaiting finance review.',
            ]);
        }

        if ($payment->status === PaymentStatus::Confirmed && $targetStatus !== PaymentStatus::Confirmed) {
            throw ValidationException::withMessages([
                'status' => 'Confirmed payments cannot be manually changed back to another status.',
            ]);
        }

        if (! in_array($targetStatus, $this->manualCorrectionAllowedTargetStatuses($payment), true)) {
            throw ValidationException::withMessages([
                'status' => 'This payment cannot be corrected to the selected status.',
            ]);
        }
    }

    /**
     * @return array<int, PaymentStatus>
     */
    private function manualCorrectionAllowedTargetStatuses(Payment $payment): array
    {
        if ($payment->status === PaymentStatus::AwaitingFinanceReview) {
            return [];
        }

        if ($payment->status === PaymentStatus::Confirmed) {
            return [PaymentStatus::Confirmed];
        }

        return [
            PaymentStatus::PendingConfirmation,
            PaymentStatus::Confirmed,
            PaymentStatus::Rejected,
            PaymentStatus::Failed,
            PaymentStatus::Expired,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentAuditSnapshot(Payment $payment): array
    {
        $payment->loadMissing(['application', 'invoice']);

        return [
            'status' => $payment->status?->value ?? (string) $payment->status,
            'provider_reference' => $payment->provider_reference,
            'provider_transaction_id' => $payment->provider_transaction_id,
            'reviewed_by_user_id' => $payment->reviewed_by_user_id,
            'reviewed_at' => optional($payment->reviewed_at)?->toIso8601String(),
            'review_comment' => $payment->review_comment,
            'rejection_reason' => $payment->rejection_reason,
            'initiated_at' => optional($payment->initiated_at)?->toIso8601String(),
            'confirmed_at' => optional($payment->confirmed_at)?->toIso8601String(),
            'failed_at' => optional($payment->failed_at)?->toIso8601String(),
            'rejected_at' => optional($payment->rejected_at)?->toIso8601String(),
            'expires_at' => optional($payment->expires_at)?->toIso8601String(),
            'last_status_at' => optional($payment->last_status_at)?->toIso8601String(),
            'invoice_status' => $payment->invoice?->status?->value ?? (string) ($payment->invoice?->status ?? ''),
            'invoice_paid_at' => optional($payment->invoice?->paid_at)?->toIso8601String(),
            'application_paid_at' => optional($payment->application?->paid_at)?->toIso8601String(),
            'application_status' => $payment->application?->current_status?->value ?? (string) ($payment->application?->current_status ?? ''),
        ];
    }

    private function confirmedPaymentNeedsStateSync(Payment $payment): bool
    {
        $payment->loadMissing(['application', 'invoice']);

        $application = $payment->application;
        $invoice = $payment->invoice;
        $applicationStatus = $application?->current_status;

        return ! $application?->submitted_at
            || $applicationStatus !== ApplicationStatus::Submitted
            || ! $application?->paid_at
            || ($invoice !== null && $invoice->status !== InvoiceStatus::Paid);
    }

    private function assertApplicationNotLockedByPendingProofReview(Application $application, ?Payment $ignoringPayment = null): void
    {
        $query = Payment::query()
            ->where('application_id', $application->id)
            ->whereIn('method', [PaymentMethod::BankDeposit, PaymentMethod::BankTransfer])
            ->where('status', PaymentStatus::AwaitingFinanceReview);

        if ($ignoringPayment) {
            $query->where('id', '!=', $ignoringPayment->id);
        }

        if (! $query->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'payment' => 'A proof of payment is already awaiting finance review. Wait for approval or rejection before making changes.',
        ]);
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
