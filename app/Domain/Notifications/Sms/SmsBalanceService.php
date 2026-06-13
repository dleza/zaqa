<?php

namespace App\Domain\Notifications\Sms;

use App\Domain\Audit\AuditLogService;
use App\Models\SmsBalanceAccount;
use App\Models\SmsBalanceAdjustment;
use App\Models\SmsLog;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

class SmsBalanceService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly SmsBalanceAlertService $alerts,
    ) {
    }

    public function isSendingAllowed(): bool
    {
        if (! (bool) config('sms.enabled')) {
            return false;
        }

        return $this->currentBalance() > 0;
    }

    public function currentBalance(): int
    {
        return (int) SmsBalanceAccount::currentReadOnly()->balance;
    }

    public function lowBalanceThreshold(): int
    {
        return (int) SmsBalanceAccount::currentReadOnly()->low_balance_threshold;
    }

    public function criticalBalanceThreshold(): int
    {
        return (int) SmsBalanceAccount::currentReadOnly()->critical_balance_threshold;
    }

    public function isLowBalance(): bool
    {
        return $this->currentBalance() <= $this->lowBalanceThreshold();
    }

    public function isCriticalBalance(): bool
    {
        return $this->currentBalance() <= $this->criticalBalanceThreshold();
    }

    public function alertLevel(): ?string
    {
        if ($this->currentBalance() <= $this->criticalBalanceThreshold()) {
            return 'critical';
        }

        if ($this->currentBalance() <= $this->lowBalanceThreshold()) {
            return 'warning';
        }

        return null;
    }

    /**
     * @return array{adjustment: SmsBalanceAdjustment, account: SmsBalanceAccount}
     */
    public function credit(int $amount, string $reason, ?Authenticatable $actor = null): array
    {
        if ($amount < 1) {
            throw new \InvalidArgumentException('Credit amount must be at least 1.');
        }

        return DB::transaction(function () use ($amount, $reason, $actor) {
            $account = SmsBalanceAccount::current();
            $before = (int) $account->balance;
            $after = $before + $amount;

            $account->forceFill(['balance' => $after])->save();
            $this->alerts->resetAlertsIfRecovered($account->fresh());

            $adjustment = SmsBalanceAdjustment::query()->create([
                'adjustment_type' => 'credit',
                'amount' => $amount,
                'reason' => $reason,
                'actor_user_id' => $actor?->getAuthIdentifier(),
                'balance_before' => $before,
                'balance_after' => $after,
                'metadata' => null,
                'created_at' => now(),
            ]);

            $this->audit->record(
                eventType: 'notifications.sms_balance_credited',
                module: 'Notifications',
                actionName: 'sms_balance_credited',
                message: "SMS balance credited by {$amount}.",
                entityType: SmsBalanceAdjustment::class,
                entityId: $adjustment->id,
                metadata: [
                    'amount' => $amount,
                    'balance_before' => $before,
                    'balance_after' => $after,
                    'reason' => $reason,
                ],
                actor: $actor,
            );

            return ['adjustment' => $adjustment, 'account' => $account->fresh()];
        });
    }

    /**
     * Debit one SMS unit after provider acceptance. Must run inside existing transaction with account lock.
     */
    public function debitForSuccessfulSend(SmsLog $log): SmsBalanceAdjustment
    {
        $account = SmsBalanceAccount::current();
        $before = (int) $account->balance;

        if ($before < 1) {
            throw new \RuntimeException('Insufficient SMS balance.');
        }

        $after = $before - 1;
        $account->forceFill(['balance' => $after])->save();

        return SmsBalanceAdjustment::query()->create([
            'adjustment_type' => 'debit',
            'amount' => 1,
            'reason' => 'SMS sent',
            'actor_user_id' => null,
            'balance_before' => $before,
            'balance_after' => $after,
            'sms_log_id' => $log->id,
            'metadata' => [
                'message_type' => $log->message_type,
                'sms_log_id' => $log->id,
            ],
            'created_at' => now(),
        ]);
    }

    /**
     * @return array{sent_today: int, failed_today: int}
     */
    public function todayStatistics(): array
    {
        $today = now()->toDateString();

        $sentToday = SmsLog::query()
            ->where('status', 'sent')
            ->whereDate('sent_at', $today)
            ->count();

        $failedToday = SmsLog::query()
            ->where('status', 'failed')
            ->whereDate('updated_at', $today)
            ->count();

        return [
            'sent_today' => $sentToday,
            'failed_today' => $failedToday,
        ];
    }
}
