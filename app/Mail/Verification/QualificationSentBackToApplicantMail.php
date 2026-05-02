<?php

namespace App\Mail\Verification;

use App\Models\Application;
use App\Models\Qualification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class QualificationSentBackToApplicantMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Qualification $qualification,
        public readonly Application $application,
        public readonly User $applicant,
        public readonly User $actor,
        public readonly string $comment,
    ) {}

    public function envelope(): Envelope
    {
        $num = $this->application->application_number;

        return new Envelope(
            subject: "ZAQA: Qualification amendment required ({$num})",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.verification.qualification-sent-back-to-applicant',
            with: [
                'qualification' => $this->qualification,
                'application' => $this->application,
                'applicant' => $this->applicant,
                'actor' => $this->actor,
                'comment' => $this->comment,
                'amendUrl' => url("/applicant/applications/{$this->application->id}/qualifications/{$this->qualification->id}/amend"),
                'loginUrl' => url('/login'),
            ],
        );
    }
}
