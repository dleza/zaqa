<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AdminStaffAccountCreatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $email,
        public readonly string $plainTextPassword,
        public readonly string $roleName,
        public readonly string $loginUrl,
    ) {
    }

    public function build(): self
    {
        return $this->subject('Your ZAQA staff account')
            ->view('emails.admin-staff-account-created', [
                'recipientName' => $this->recipientName,
                'email' => $this->email,
                'plainTextPassword' => $this->plainTextPassword,
                'roleName' => $this->roleName,
                'loginUrl' => $this->loginUrl,
            ]);
    }
}
