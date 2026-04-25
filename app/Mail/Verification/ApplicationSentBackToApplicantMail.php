<?php

namespace App\Mail\Verification;

use App\Models\Application;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ApplicationSentBackToApplicantMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Application $application,
        public readonly User $applicant,
        public readonly User $actor,
        public readonly string $comment,
    ) {
    }

    public function envelope(): Envelope
    {
        $num = $this->application->application_number;

        return new Envelope(
            subject: "ZAQA: Application {$num} sent back for amendments",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.verification.sent-back-to-applicant',
            with: [
                'application' => $this->application,
                'applicant' => $this->applicant,
                'actor' => $this->actor,
                'comment' => $this->comment,
                'trackUrl' => url("/applicant/applications/{$this->application->id}/track"),
                'loginUrl' => url('/login'),
            ],
        );
    }
}

