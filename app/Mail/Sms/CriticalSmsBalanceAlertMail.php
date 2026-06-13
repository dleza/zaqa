<?php

namespace App\Mail\Sms;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CriticalSmsBalanceAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly int $balance,
        public readonly int $threshold,
    ) {
    }

    public function build(): self
    {
        return $this->subject('[ZAQA] SMS Balance Critical')
            ->view('emails.sms.critical_balance_alert', [
                'balance' => $this->balance,
                'threshold' => $this->threshold,
            ]);
    }
}
