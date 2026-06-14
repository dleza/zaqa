<?php

namespace App\Mail;

use App\Models\InstitutionApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InstitutionPullLookupTokenIssuedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly InstitutionApiClient $client,
        public readonly string $plainTextToken,
        public readonly ?string $lookupUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'ZAQA Pull Lookup Integration Token',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.institution-pull-lookup-token-issued',
        );
    }
}
