<?php

namespace App\Mail\Finance;

use App\Models\Application;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentProofApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Payment $payment,
        public readonly Application $application,
        public readonly User $applicant,
        public readonly User $actor,
        public readonly ?string $comment = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'ZAQA: Payment confirmed',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.finance.payment_proof_approved',
            with: [
                'payment' => $this->payment,
                'application' => $this->application,
                'applicant' => $this->applicant,
                'actor' => $this->actor,
                'comment' => $this->comment,
                'nextUrl' => route('applicant.applications.edit', ['application' => $this->application->id, 'step' => 'payment']),
            ],
        );
    }
}
