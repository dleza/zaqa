<?php

namespace App\Domain\Finance;

use App\Domain\Audit\AuditLogService;
use App\Domain\Payments\PaymentGatewayManager;
use App\Domain\Payments\PaymentService;
use App\Domain\Payments\Gateways\CGrate\CGrateException;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FinancePaymentGatewayRecheckService
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly PaymentService $payments,
        private readonly AuditLogService $audit,
    ) {
    }

    public function canRecheck(Payment $payment): bool
    {
        if ($payment->status === PaymentStatus::AwaitingFinanceReview) {
            return false;
        }

        if ($payment->method !== PaymentMethod::MobileMoney || $payment->provider !== 'cgrate') {
            return false;
        }

        return trim((string) $payment->provider_reference) !== '';
    }

    public function unsupportedReason(Payment $payment): ?string
    {
        if ($payment->status === PaymentStatus::AwaitingFinanceReview) {
            return 'Use the payment proof review flow while this payment is awaiting finance review.';
        }

        if ($payment->method === PaymentMethod::BankDeposit || $payment->method === PaymentMethod::BankTransfer) {
            return 'Manual bank payments are not checked against an online payment gateway.';
        }

        if ($payment->method === PaymentMethod::Card) {
            return 'Card payment status must be checked in the CyberSource console for now.';
        }

        if ($payment->method === PaymentMethod::MobileMoney && $payment->provider !== 'cgrate') {
            return 'Gateway recheck is only available for cGrate mobile money payments.';
        }

        if (trim((string) $payment->provider_reference) === '') {
            return 'This payment does not have a provider reference to query.';
        }

        return null;
    }

    /**
     * @return array{
     *     supported: bool,
     *     unsupported_reason: string|null,
     *     local_status: string,
     *     local_status_label: string,
     *     gateway_status: string,
     *     gateway_status_label: string,
     *     status_changed: bool,
     *     response_code: int|null,
     *     response_message: string|null,
     *     provider_transaction_id: string|null,
     *     will_submit_application: bool,
     *     application_id: int|null,
     *     application_status: string|null,
     *     raw_payload: array<string, mixed>|null
     * }
     */
    public function recheck(Payment $payment): array
    {
        $unsupportedReason = $this->unsupportedReason($payment);
        if ($unsupportedReason !== null) {
            return $this->unsupportedResult($payment, $unsupportedReason);
        }

        $reference = trim((string) $payment->provider_reference);

        try {
            $verified = $this->gateways->gateway('cgrate')->verify($reference, []);
        } catch (CGrateException $e) {
            throw ValidationException::withMessages([
                'gateway' => 'Could not query the payment gateway: '.$e->getMessage(),
            ]);
        }

        $gatewayStatus = (string) ($verified['status'] ?? 'unknown');
        $localStatus = $this->mapGatewayStatusToPaymentStatus($gatewayStatus);
        $payment->loadMissing('application');

        $localValue = $payment->status?->value ?? (string) $payment->status;
        $gatewayValue = $localStatus->value;
        $statusChanged = $localValue !== $gatewayValue;

        $cgrate = (array) (($verified['raw_payload'] ?? [])['cgrate'] ?? []);

        return [
            'supported' => true,
            'unsupported_reason' => null,
            'local_status' => $localValue,
            'local_status_label' => $this->paymentStatusLabel($payment->status),
            'gateway_status' => $gatewayValue,
            'gateway_status_label' => $this->paymentStatusLabel($localStatus),
            'status_changed' => $statusChanged,
            'response_code' => array_key_exists('response_code', $cgrate) ? (int) $cgrate['response_code'] : null,
            'response_message' => isset($cgrate['response_message']) ? (string) $cgrate['response_message'] : null,
            'provider_transaction_id' => $verified['provider_transaction_id'] ?? null,
            'will_submit_application' => $statusChanged
                && $localStatus === PaymentStatus::Confirmed
                && $payment->status !== PaymentStatus::Confirmed,
            'application_id' => $payment->application_id ? (int) $payment->application_id : null,
            'application_status' => $payment->application?->current_status,
            'raw_payload' => is_array($verified['raw_payload'] ?? null) ? $verified['raw_payload'] : null,
        ];
    }

    public function apply(Payment $payment, User $actor, string $note): Payment
    {
        $note = trim($note);
        if ($note === '') {
            throw ValidationException::withMessages([
                'note' => 'A note is required before applying the gateway status.',
            ]);
        }

        if ($payment->status === PaymentStatus::Confirmed) {
            throw ValidationException::withMessages([
                'payment' => 'This payment is already confirmed and cannot be changed from a gateway recheck.',
            ]);
        }

        $result = $this->recheck($payment);
        if (! ($result['supported'] ?? false)) {
            throw ValidationException::withMessages([
                'gateway' => (string) ($result['unsupported_reason'] ?? 'Gateway recheck is not available for this payment.'),
            ]);
        }

        if (! ($result['status_changed'] ?? false)) {
            throw ValidationException::withMessages([
                'payment' => 'The gateway status matches the recorded payment status. No update is required.',
            ]);
        }

        return DB::transaction(function () use ($payment, $actor, $note, $result) {
            $payment = Payment::query()
                ->with(['application', 'invoice', 'attempts'])
                ->lockForUpdate()
                ->findOrFail($payment->id);

            if ($payment->status === PaymentStatus::Confirmed) {
                return $payment;
            }

            $gatewayStatus = $this->mapPaymentStatusToGatewayStatus(
                PaymentStatus::from((string) $result['gateway_status']),
            );

            $before = [
                'status' => $payment->status?->value ?? (string) $payment->status,
                'provider_transaction_id' => $payment->provider_transaction_id,
            ];

            $payment = $this->payments->applyGatewayVerificationResult(
                payment: $payment,
                status: $gatewayStatus,
                verified: [
                    'provider_transaction_id' => $result['provider_transaction_id'] ?? $payment->provider_transaction_id,
                    'raw_payload' => $result['raw_payload'],
                ],
                eventType: 'finance.gateway_recheck',
                actor: $actor,
            );

            $this->syncLatestAttempt($payment, $gatewayStatus, $result);
            $payment->refresh()->loadMissing(['application', 'invoice']);

            $after = [
                'status' => $payment->status?->value ?? (string) $payment->status,
                'provider_transaction_id' => $payment->provider_transaction_id,
            ];

            $this->audit->record(
                eventType: 'finance.payment_gateway_recheck_applied',
                module: 'Finance',
                actionName: 'payment_gateway_recheck_applied',
                message: 'Finance applied a gateway status recheck to this payment.',
                entityType: Payment::class,
                entityId: $payment->id,
                beforeState: $before,
                afterState: $after,
                metadata: [
                    'application_id' => $payment->application_id,
                    'invoice_id' => $payment->invoice_id,
                    'note' => $note,
                    'gateway_status' => $result['gateway_status'],
                    'response_code' => $result['response_code'],
                    'response_message' => $result['response_message'],
                    'application_submitted' => ($result['will_submit_application'] ?? false) && $after['status'] === PaymentStatus::Confirmed->value,
                ],
                actor: $actor,
            );

            return $payment;
        });
    }

    /**
     * @param array<string, mixed> $result
     */
    private function syncLatestAttempt(Payment $payment, string $gatewayStatus, array $result): void
    {
        $attempt = $payment->attempts
            ->sortByDesc('id')
            ->first(fn (PaymentAttempt $attempt) => $attempt->gateway === $payment->provider);

        if (! $attempt) {
            return;
        }

        $attemptStatus = match ($gatewayStatus) {
            'confirmed' => PaymentAttemptStatus::Confirmed,
            'rejected' => PaymentAttemptStatus::Rejected,
            'failed' => PaymentAttemptStatus::Failed,
            'expired' => PaymentAttemptStatus::Expired,
            default => PaymentAttemptStatus::Pending,
        };

        $attempt->forceFill([
            'status' => $attemptStatus,
            'response_code' => $result['response_code'] ?? $attempt->response_code,
            'response_message' => $result['response_message'] ?? $attempt->response_message,
            'provider_transaction_id' => $result['provider_transaction_id'] ?? $attempt->provider_transaction_id,
            'response_payload' => $result['raw_payload'] ?? $attempt->response_payload,
            'last_queried_at' => now(),
            'confirmed_at' => $attemptStatus === PaymentAttemptStatus::Confirmed ? ($attempt->confirmed_at ?? now()) : $attempt->confirmed_at,
            'failed_at' => $attemptStatus === PaymentAttemptStatus::Failed ? ($attempt->failed_at ?? now()) : $attempt->failed_at,
            'rejected_at' => $attemptStatus === PaymentAttemptStatus::Rejected ? ($attempt->rejected_at ?? now()) : $attempt->rejected_at,
            'expired_at' => $attemptStatus === PaymentAttemptStatus::Expired ? ($attempt->expired_at ?? now()) : $attempt->expired_at,
        ])->save();
    }

    private function mapGatewayStatusToPaymentStatus(string $gatewayStatus): PaymentStatus
    {
        return match ($gatewayStatus) {
            'confirmed' => PaymentStatus::Confirmed,
            'rejected' => PaymentStatus::Rejected,
            'expired' => PaymentStatus::Expired,
            'pending', 'unknown' => PaymentStatus::PendingConfirmation,
            default => PaymentStatus::Failed,
        };
    }

    private function mapPaymentStatusToGatewayStatus(PaymentStatus $status): string
    {
        return match ($status) {
            PaymentStatus::Confirmed => 'confirmed',
            PaymentStatus::Rejected => 'rejected',
            PaymentStatus::Expired => 'expired',
            PaymentStatus::PendingConfirmation, PaymentStatus::Initiated, PaymentStatus::Draft => 'pending',
            default => 'failed',
        };
    }

    private function paymentStatusLabel(?PaymentStatus $status): string
    {
        return match ($status) {
            PaymentStatus::PendingConfirmation => 'Pending confirmation',
            PaymentStatus::Confirmed => 'Confirmed',
            PaymentStatus::Rejected => 'Rejected',
            PaymentStatus::Failed => 'Failed',
            PaymentStatus::Expired => 'Expired',
            PaymentStatus::Draft => 'Draft',
            PaymentStatus::Initiated => 'Initiated',
            PaymentStatus::AwaitingFinanceReview => 'Awaiting finance review',
            default => $status?->value ?? 'Unknown',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function unsupportedResult(Payment $payment, string $reason): array
    {
        $payment->loadMissing('application');

        return [
            'supported' => false,
            'unsupported_reason' => $reason,
            'local_status' => $payment->status?->value ?? (string) $payment->status,
            'local_status_label' => $this->paymentStatusLabel($payment->status),
            'gateway_status' => $payment->status?->value ?? (string) $payment->status,
            'gateway_status_label' => $this->paymentStatusLabel($payment->status),
            'status_changed' => false,
            'response_code' => null,
            'response_message' => null,
            'provider_transaction_id' => $payment->provider_transaction_id,
            'will_submit_application' => false,
            'application_id' => $payment->application_id ? (int) $payment->application_id : null,
            'application_status' => $payment->application?->current_status,
            'raw_payload' => null,
        ];
    }
}
