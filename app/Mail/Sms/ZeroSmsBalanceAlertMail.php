<?php

namespace App\Mail\Sms;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ZeroSmsBalanceAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function build(): self
    {
        return $this->subject('[ZAQA] SMS Balance Exhausted')
            ->view('emails.sms.zero_balance_alert');
    }
}
