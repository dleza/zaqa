<?php

namespace App\Mail;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ActivationEmailMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $activationUrl,
        public readonly CarbonImmutable $expiresAt,
    ) {
    }

    public function build(): self
    {
        return $this->subject('Activate your ZAQA account')
            ->view('emails.auth.activate_account', [
                'recipientName' => $this->recipientName,
                'activationUrl' => $this->activationUrl,
                'expiresAt' => $this->expiresAt,
            ]);
    }
}

