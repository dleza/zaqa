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

class ApplicationLevel1CompletedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Application $application,
        public readonly User $level1Actor,
        public readonly User $assignedBy,
        public readonly string $findings,
    ) {
    }

    public function envelope(): Envelope
    {
        $num = $this->application->application_number;

        return new Envelope(
            subject: "ZAQA: Level 1 review completed for {$num}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.verification.level1-completed',
            with: [
                'application' => $this->application,
                'level1Actor' => $this->level1Actor,
                'assignedBy' => $this->assignedBy,
                'findings' => $this->findings,
                'adminUrl' => url("/admin/verification/applications/{$this->application->id}"),
            ],
        );
    }
}

