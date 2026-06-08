<?php

namespace App\Domain\Payments;

use App\Enums\PaymentAttemptStatus;
use App\Jobs\Payments\QueryCGratePaymentAttemptJob;
use App\Models\PaymentAttempt;
use App\Support\Payments\PaymentQueue;
use Illuminate\Support\Facades\DB;

final class CGratePollingService
{
    /**
     * Dispatch polling jobs for due (next_query_at <= now) cGrate attempts.
     */
    public function dispatchDueAttempts(int $limit = 50): int
    {
        if (! (bool) config('cgrate.enabled')) {
            return 0;
        }

        $now = now();

        return DB::transaction(function () use ($now, $limit) {
            $attempts = PaymentAttempt::query()
                ->where('gateway', 'cgrate')
                ->whereIn('status', [PaymentAttemptStatus::Initiated, PaymentAttemptStatus::Pending])
                ->where(function ($q) use ($now) {
                    $q->whereNull('next_query_at')->orWhere('next_query_at', '<=', $now);
                })
                ->orderByRaw('next_query_at is null desc')
                ->orderBy('next_query_at')
                ->limit($limit)
                ->lockForUpdate()
                ->get(['id']);

            foreach ($attempts as $attempt) {
                QueryCGratePaymentAttemptJob::dispatch((int) $attempt->id)
                    ->onQueue(PaymentQueue::polling())
                    ->afterCommit();
            }

            return $attempts->count();
        });
    }
}
