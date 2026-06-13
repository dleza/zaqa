<?php

namespace App\Domain\Notifications\Sms;

use App\Domain\Notifications\OutboundMailService;
use App\Mail\Sms\CriticalSmsBalanceAlertMail;
use App\Mail\Sms\LowSmsBalanceAlertMail;
use App\Mail\Sms\ZeroSmsBalanceAlertMail;
use App\Models\SmsBalanceAccount;
use Illuminate\Support\Facades\DB;

class SmsBalanceAlertService
{
    public function __construct(
        private readonly OutboundMailService $mail,
    ) {
    }

    /**
     * @return list<string>
     */
    public function recipients(): array
    {
        $configured = config('sms.alert_emails', []);

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_filter(
            $configured,
            static fn ($email) => is_string($email) && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL),
        ));
    }

    public function evaluateAfterDebit(int $balanceBefore, int $balanceAfter): void
    {
        if ($balanceAfter >= $balanceBefore) {
            return;
        }

        if ($this->recipients() === []) {
            return;
        }

        DB::transaction(function () use ($balanceBefore, $balanceAfter) {
            $account = SmsBalanceAccount::current();
            $lowThreshold = (int) $account->low_balance_threshold;
            $criticalThreshold = (int) $account->critical_balance_threshold;
            $now = now();

            if ($balanceBefore > 0
                && $balanceAfter === 0
                && $account->zero_balance_alert_sent_at === null) {
                $this->queueZeroAlert($account);
                $account->forceFill(['zero_balance_alert_sent_at' => $now])->save();
            }

            if ($balanceBefore > $criticalThreshold
                && $balanceAfter <= $criticalThreshold
                && $account->critical_balance_alert_sent_at === null) {
                $this->queueCriticalAlert($account);
                $account->forceFill(['critical_balance_alert_sent_at' => $now])->save();
            }

            if ($balanceBefore > $lowThreshold
                && $balanceAfter <= $lowThreshold
                && $account->low_balance_alert_sent_at === null) {
                $this->queueLowAlert($account);
                $account->forceFill(['low_balance_alert_sent_at' => $now])->save();
            }
        });
    }

    public function resetAlertsIfRecovered(SmsBalanceAccount $account): void
    {
        $balance = (int) $account->balance;
        $lowThreshold = (int) $account->low_balance_threshold;
        $criticalThreshold = (int) $account->critical_balance_threshold;

        $updates = [];

        if ($balance > $lowThreshold) {
            $updates['low_balance_alert_sent_at'] = null;
        }

        if ($balance > $criticalThreshold) {
            $updates['critical_balance_alert_sent_at'] = null;
        }

        if ($balance > 0) {
            $updates['zero_balance_alert_sent_at'] = null;
        }

        if ($updates !== []) {
            $account->forceFill($updates)->save();
        }
    }

    private function queueLowAlert(SmsBalanceAccount $account): void
    {
        $recipients = $this->recipients();
        if ($recipients === []) {
            return;
        }

        $this->mail->queue(
            mailable: new LowSmsBalanceAlertMail(
                balance: (int) $account->balance,
                threshold: (int) $account->low_balance_threshold,
            ),
            to: $recipients,
            logContext: [
                'user_id' => null,
                'application_id' => null,
                'email' => $recipients[0],
                'subject' => '[ZAQA] SMS Balance Low',
                'template_key' => 'sms_balance_low_alert',
            ],
        );
    }

    private function queueCriticalAlert(SmsBalanceAccount $account): void
    {
        $recipients = $this->recipients();
        if ($recipients === []) {
            return;
        }

        $this->mail->queue(
            mailable: new CriticalSmsBalanceAlertMail(
                balance: (int) $account->balance,
                threshold: (int) $account->critical_balance_threshold,
            ),
            to: $recipients,
            logContext: [
                'user_id' => null,
                'application_id' => null,
                'email' => $recipients[0],
                'subject' => '[ZAQA] SMS Balance Critical',
                'template_key' => 'sms_balance_critical_alert',
            ],
        );
    }

    private function queueZeroAlert(SmsBalanceAccount $account): void
    {
        $recipients = $this->recipients();
        if ($recipients === []) {
            return;
        }

        $this->mail->queue(
            mailable: new ZeroSmsBalanceAlertMail(),
            to: $recipients,
            logContext: [
                'user_id' => null,
                'application_id' => null,
                'email' => $recipients[0],
                'subject' => '[ZAQA] SMS Balance Exhausted',
                'template_key' => 'sms_balance_zero_alert',
            ],
        );
    }
}
