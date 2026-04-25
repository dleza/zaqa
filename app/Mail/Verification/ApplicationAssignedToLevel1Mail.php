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

class ApplicationAssignedToLevel1Mail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Application $application,
        public readonly User $assignedBy,
        public readonly User $assignedTo,
        public readonly ?string $comment = null,
    ) {
    }

    public function envelope(): Envelope
    {
        $num = $this->application->application_number;

        return new Envelope(
            subject: "ZAQA: Application {$num} assigned for Level 1 review",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.verification.assigned-to-level1',
            with: [
                'application' => $this->application,
                'assignedBy' => $this->assignedBy,
                'assignedTo' => $this->assignedTo,
                'comment' => $this->comment,
                'adminUrl' => url("/admin/verification/applications/{$this->application->id}"),
            ],
        );
    }
}

