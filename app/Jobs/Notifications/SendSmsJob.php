<?php

namespace App\Jobs\Notifications;

use App\Domain\Notifications\Sms\SmsBalanceAlertService;
use App\Domain\Notifications\Sms\SmsBalanceService;
use App\Domain\Notifications\Sms\SmsProviderManager;
use App\Models\SmsLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 90;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public readonly int $smsLogId,
    ) {
    }

    public function handle(SmsProviderManager $providers, SmsBalanceService $balance, SmsBalanceAlertService $alerts): void
    {
        $log = SmsLog::query()->find($this->smsLogId);
        if (! $log || $log->status !== 'queued') {
            return;
        }

        if (! (bool) config('sms.enabled')) {
            $this->markSkipped($log, 'disabled');

            return;
        }

        if ($balance->currentBalance() < 1) {
            $this->markSkipped($log, 'insufficient_balance');

            return;
        }

        $provider = $providers->resolve();
        $contacts = (string) ($log->normalized_phone ?? $log->phone_number);

        try {
            $result = $provider->send($contacts, (string) $log->message_body);
        } catch (\Throwable $e) {
            Log::warning('SMS provider threw an exception.', [
                'sms_log_id' => $log->id,
                'message_type' => $log->message_type,
                'error' => $e->getMessage(),
            ]);

            $this->markFailed($log, 0, ['success' => false, 'message' => 'Provider exception.'], null);

            if ($this->attempts() < $this->tries) {
                throw $e;
            }

            return;
        }

        $log->forceFill([
            'attempt_count' => (int) $log->attempt_count + 1,
            'http_status' => $result->httpStatus,
            'provider_response' => $result->sanitizedResponse,
            'provider_reference' => $result->providerReference,
        ])->save();

        if ($result->accepted) {
            $adjustment = null;

            DB::transaction(function () use ($log, $balance, $result, &$adjustment) {
                $locked = SmsLog::query()->lockForUpdate()->findOrFail($log->id);
                if ($locked->status !== 'queued') {
                    return;
                }

                $adjustment = $balance->debitForSuccessfulSend($locked);

                $locked->forceFill([
                    'status' => 'sent',
                    'sent_at' => now(),
                    'balance_adjustment_id' => $adjustment->id,
                    'http_status' => $result->httpStatus,
                    'provider_response' => $result->sanitizedResponse,
                    'provider_reference' => $result->providerReference,
                ])->save();
            });

            if ($adjustment !== null) {
                $alerts->evaluateAfterDebit(
                    balanceBefore: (int) $adjustment->balance_before,
                    balanceAfter: (int) $adjustment->balance_after,
                );
            }

            return;
        }

        $this->markFailed($log, $result->httpStatus, $result->sanitizedResponse, $result->providerReference);

        if ($result->shouldRetry() && $this->attempts() < $this->tries) {
            throw new \RuntimeException($result->failureReason ?? 'transient_sms_failure');
        }
    }

    private function markSkipped(SmsLog $log, string $reason): void
    {
        $log->forceFill([
            'status' => 'skipped',
            'skip_reason' => $reason,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function markFailed(SmsLog $log, int $httpStatus, array $response, ?string $reference): void
    {
        $log->forceFill([
            'status' => 'failed',
            'http_status' => $httpStatus > 0 ? $httpStatus : $log->http_status,
            'provider_response' => $response,
            'provider_reference' => $reference,
            'attempt_count' => (int) $log->attempt_count + 1,
        ])->save();
    }
}
