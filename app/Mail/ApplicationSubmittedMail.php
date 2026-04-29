<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ApplicationSubmittedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $applicationNumber,
        public readonly string $trackingUrl,
        public readonly bool $isResubmission,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->isResubmission ? 'ZAQA application resubmitted' : 'ZAQA application submitted',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.applications.application_submitted',
            with: [
                'recipientName' => $this->recipientName,
                'applicationNumber' => $this->applicationNumber,
                'trackingUrl' => $this->trackingUrl,
                'isResubmission' => $this->isResubmission,
            ],
        );
    }
}
