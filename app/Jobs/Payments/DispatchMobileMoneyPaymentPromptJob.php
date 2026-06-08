<?php

namespace App\Jobs\Payments;

use App\Domain\Payments\PaymentGatewayManager;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\PaymentAttempt;
use App\Support\Payments\PaymentQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class DispatchMobileMoneyPaymentPromptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly int $paymentAttemptId,
    ) {
        $this->onQueue(PaymentQueue::high());
    }

    public function handle(PaymentGatewayManager $gateways): void
    {
        if (! (bool) config('cgrate.enabled')) {
            return;
        }

        $attempt = PaymentAttempt::query()
            ->with('payment')
            ->find($this->paymentAttemptId);

        if (! $attempt || $attempt->status?->isTerminal()) {
            return;
        }

        if ($attempt->status !== PaymentAttemptStatus::Initiated) {
            $this->schedulePolling((int) $attempt->id);

            return;
        }

        $payment = $attempt->payment;
        if (! $payment) {
            return;
        }

        $pollInterval = (int) config('cgrate.poll_interval_seconds', 10);
        $gateway = $gateways->gateway('cgrate');

        try {
            $result = $gateway->initiate($payment, PaymentMethod::MobileMoney, [
                'mobile_number' => (string) $attempt->mobile_number,
                'payment_reference' => (string) $attempt->payment_reference,
            ]);

            $cgrate = (array) (($result['raw_payload'] ?? [])['cgrate'] ?? []);
            $code = array_key_exists('response_code', $cgrate) ? (int) $cgrate['response_code'] : null;
            $message = (string) ($cgrate['response_message'] ?? '');

            DB::transaction(function () use ($attempt, $result, $code, $message) {
                $locked = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
                if ($locked->status?->isTerminal() || $locked->status !== PaymentAttemptStatus::Initiated) {
                    return;
                }

                $locked->forceFill([
                    'provider_transaction_id' => $result['provider_transaction_id'] ?: $locked->provider_transaction_id,
                    'response_code' => $code,
                    'response_message' => $message !== '' ? $message : $locked->response_message,
                    'response_payload' => $result['raw_payload'] ?? null,
                    'status' => $code === 0 ? PaymentAttemptStatus::Pending : PaymentAttemptStatus::Failed,
                    'failed_at' => $code === 0 ? null : now(),
                    'next_query_at' => $code === 0 ? now() : null,
                ])->save();

                $locked->payment()->update([
                    'provider_transaction_id' => $result['provider_transaction_id'] ?: $locked->payment->provider_transaction_id,
                    'raw_payload' => $result['raw_payload'] ?? $locked->payment->raw_payload,
                    'last_status_at' => now(),
                    'status' => $code === 0 ? PaymentStatus::PendingConfirmation : PaymentStatus::Failed,
                    'failed_at' => $code === 0 ? null : now(),
                ]);
            });
        } catch (\Throwable $e) {
            DB::transaction(function () use ($attempt, $e) {
                $locked = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
                if ($locked->status?->isTerminal()) {
                    return;
                }

                $locked->forceFill([
                    'status' => PaymentAttemptStatus::Pending,
                    'response_message' => $locked->response_message ?? 'Could not confirm initiation response (will retry).',
                    'metadata' => array_merge((array) ($locked->metadata ?? []), [
                        'initiation_error' => $e->getMessage(),
                    ]),
                    'next_query_at' => now(),
                ])->save();

                $locked->payment()->update([
                    'status' => PaymentStatus::PendingConfirmation,
                    'last_status_at' => now(),
                ]);
            });
        }

        $attempt->refresh();
        if ($attempt->status === PaymentAttemptStatus::Pending) {
            $this->schedulePolling((int) $attempt->id, $pollInterval);
        }
    }

    private function schedulePolling(int $attemptId, ?int $pollInterval = null): void
    {
        $seconds = max(1, $pollInterval ?? (int) config('cgrate.poll_interval_seconds', 10));

        if ((string) config('queue.default') === 'sync') {
            return;
        }

        QueryCGratePaymentAttemptJob::dispatch($attemptId)
            ->onQueue(PaymentQueue::polling())
            ->delay(now()->addSeconds($seconds));
    }
}
