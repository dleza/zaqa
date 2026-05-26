<?php

namespace App\Mail;

use App\Models\InstitutionApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InstitutionApiTokenIssuedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<int,string>  $abilities
     */
    public function __construct(
        public readonly InstitutionApiClient $client,
        public readonly string $plainTextToken,
        public readonly array $abilities,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'ZAQA Institution API Access Token',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.institution-api-token-issued',
        );
    }
}

