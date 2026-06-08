<?php

namespace App\Jobs\Payments;

use App\Domain\Payments\PaymentService;
use App\Domain\Payments\Gateways\CGrate\CGrateClient;
use App\Domain\Payments\Gateways\CGrate\CGrateException;
use App\Enums\PaymentAttemptStatus;
use App\Models\PaymentAttempt;
use App\Support\Payments\PaymentQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class QueryCGratePaymentAttemptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly int $paymentAttemptId,
    ) {
        $this->onQueue(PaymentQueue::polling());
    }

    public function handle(CGrateClient $client, PaymentService $payments): void
    {
        if (! (bool) config('cgrate.enabled')) {
            return;
        }

        $attempt = PaymentAttempt::query()
            ->with(['payment.invoice'])
            ->find($this->paymentAttemptId);

        if (! $attempt) {
            return;
        }

        if ($attempt->status?->isTerminal()) {
            return;
        }

        $now = now();

        $maxAttempts = (int) config('cgrate.max_query_attempts', 30);
        $pollInterval = (int) config('cgrate.poll_interval_seconds', 10);
        $expiryMinutes = (int) config('cgrate.payment_expiry_minutes', 10);
        $unknownFailAfter = (int) config('cgrate.unknown_fail_after_attempts', 5);

        if ($attempt->next_query_at && $attempt->next_query_at->isFuture()) {
            return;
        }

        $initiatedAt = $attempt->initiated_at ?? $attempt->created_at;
        if ($initiatedAt && $initiatedAt->copy()->addMinutes($expiryMinutes)->lessThanOrEqualTo($now)) {
            $this->expireAttempt($attempt, $payments);
            return;
        }

        if ($attempt->query_attempts >= $maxAttempts) {
            $this->expireAttempt($attempt, $payments);
            return;
        }

        try {
            $resp = $client->queryCustomerPayment((string) $attempt->payment_reference);
        } catch (CGrateException $e) {
            $this->markPendingAndReschedule(
                attemptId: (int) $attempt->id,
                message: $e->getMessage(),
                pollInterval: $pollInterval,
            );
            return;
        } catch (\Throwable $e) {
            $this->markPendingAndReschedule(
                attemptId: (int) $attempt->id,
                message: 'Query failed (will retry).',
                pollInterval: $pollInterval,
            );
            return;
        }

        $normalized = $this->normalizeQueryResult(
            responseCode: $resp->responseCode,
            responseIsApproved: $resp->isApproved(),
            responseIsRejected: $resp->isRejected(),
            responseIsFailed: $resp->isFailed(),
            responseIsPending: $resp->isPending(),
            responseIsUnknown: $resp->isUnknown(),
            responseIsConfigOrAuthError: $resp->isConfigOrAuthError(),
        );

        $result = DB::transaction(function () use ($attempt, $resp, $normalized, $pollInterval, $unknownFailAfter) {
            $locked = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if ($locked->status?->isTerminal()) {
                return [
                    'status' => $locked->status?->value ?? 'unknown',
                    'should_reschedule' => false,
                ];
            }

            $queryAttempts = (int) $locked->query_attempts + 1;
            $locked->query_attempts = $queryAttempts;
            $locked->last_queried_at = now();
            $locked->response_code = $resp->responseCode;
            $locked->response_message = $resp->responseMessage;
            $locked->provider_transaction_id = $resp->paymentId ?: $locked->provider_transaction_id;
            $locked->response_payload = $resp->raw;

            $status = $normalized;
            if ($normalized === 'unknown' && $queryAttempts < $unknownFailAfter) {
                $status = 'pending';
            }

            if ($status === 'confirmed') {
                $locked->status = PaymentAttemptStatus::Confirmed;
                $locked->confirmed_at = now();
            } elseif ($status === 'rejected') {
                $locked->status = PaymentAttemptStatus::Rejected;
                $locked->rejected_at = now();
            } elseif ($status === 'failed') {
                $locked->status = PaymentAttemptStatus::Failed;
                $locked->failed_at = now();
            } elseif ($status === 'pending') {
                $locked->status = PaymentAttemptStatus::Pending;
                $locked->next_query_at = now()->addSeconds(max(1, $pollInterval));
            } else { // unknown (terminal after threshold)
                $locked->status = PaymentAttemptStatus::Unknown;
                $locked->failed_at = $locked->failed_at ?? now();
            }

            $locked->save();

            return [
                'status' => $status,
                'should_reschedule' => $status === 'pending',
            ];
        });

        // Update the Payment row (and invoice/application if confirmed) idempotently.
        $attempt->refresh()->loadMissing('payment.invoice');
        if ($attempt->payment) {
            $paymentStatus = (string) ($result['status'] ?? 'failed');
            if ($paymentStatus === 'unknown') {
                $paymentStatus = 'failed';
            }

            $payments->applyGatewayVerificationResult(
                payment: $attempt->payment,
                status: $paymentStatus,
                verified: [
                    'provider_transaction_id' => $attempt->provider_transaction_id,
                    'raw_payload' => is_array($attempt->response_payload) ? $attempt->response_payload : null,
                ],
                eventType: 'cgrate.query',
            );
        }

        if ((bool) ($result['should_reschedule'] ?? false)) {
            $this->dispatchAgainIfAsync($attempt->id, $pollInterval);
        }
    }

    private function normalizeQueryResult(
        ?int $responseCode,
        bool $responseIsApproved,
        bool $responseIsRejected,
        bool $responseIsFailed,
        bool $responseIsPending,
        bool $responseIsUnknown,
        bool $responseIsConfigOrAuthError,
    ): string {
        if ($responseIsApproved) {
            return 'confirmed';
        }
        if ($responseIsRejected) {
            return 'rejected';
        }
        if ($responseIsFailed) {
            return 'failed';
        }
        if ($responseIsPending) {
            return 'pending';
        }
        if ($responseIsUnknown) {
            return 'unknown';
        }

        // responseCode=0 on query is ambiguous across environments; treat as pending by default.
        if ($responseCode === 0) {
            return 'pending';
        }

        if ($responseIsConfigOrAuthError) {
            return 'failed';
        }

        return 'unknown';
    }

    private function expireAttempt(PaymentAttempt $attempt, PaymentService $payments): void
    {
        DB::transaction(function () use ($attempt) {
            $locked = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if ($locked->status?->isTerminal()) {
                return;
            }

            $locked->status = PaymentAttemptStatus::Expired;
            $locked->expired_at = now();
            $locked->save();
        });

        $attempt->refresh()->loadMissing('payment.invoice');
        if ($attempt->payment) {
            $payments->applyGatewayVerificationResult(
                payment: $attempt->payment,
                status: 'expired',
                verified: [
                    'provider_transaction_id' => $attempt->provider_transaction_id,
                    'raw_payload' => is_array($attempt->response_payload) ? $attempt->response_payload : null,
                ],
                eventType: 'cgrate.expired',
            );
        }
    }

    private function markPendingAndReschedule(int $attemptId, string $message, int $pollInterval): void
    {
        DB::transaction(function () use ($attemptId, $message, $pollInterval) {
            $attempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($attemptId);
            if ($attempt->status?->isTerminal()) {
                return;
            }

            $attempt->forceFill([
                'status' => PaymentAttemptStatus::Pending,
                'response_message' => $message !== '' ? $message : ($attempt->response_message ?? null),
                'last_queried_at' => now(),
                'query_attempts' => (int) $attempt->query_attempts + 1,
                'next_query_at' => now()->addSeconds(max(1, $pollInterval)),
            ])->save();
        });

        $this->dispatchAgainIfAsync($attemptId, $pollInterval);
    }

    private function dispatchAgainIfAsync(int $attemptId, int $pollInterval): void
    {
        // The sync queue driver ignores delays and would recurse immediately.
        if ((string) config('queue.default') === 'sync') {
            return;
        }

        self::dispatch($attemptId)
            ->onQueue(PaymentQueue::polling())
            ->delay(now()->addSeconds(max(1, $pollInterval)));
    }
}
