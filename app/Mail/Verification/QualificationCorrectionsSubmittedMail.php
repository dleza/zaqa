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

class QualificationCorrectionsSubmittedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Qualification $qualification,
        public readonly Application $application,
        public readonly User $applicant,
        public readonly User $officer,
    ) {}

    public function envelope(): Envelope
    {
        $num = (string) ($this->application->application_number ?? '');

        return new Envelope(
            subject: "ZAQA: Applicant corrections submitted ({$num})",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.verification.qualification-corrections-submitted',
            with: [
                'qualification' => $this->qualification,
                'application' => $this->application,
                'applicant' => $this->applicant,
                'officer' => $this->officer,
                'adminUrl' => url("/admin/verification/qualifications/{$this->qualification->id}"),
            ],
        );
    }
}
