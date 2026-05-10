<?php

namespace App\Mail\Verification;

use App\Models\Qualification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class QualificationAssignedToVerifierMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Qualification $qualification,
        public readonly User $assignedBy,
        public readonly User $assignedTo,
        public readonly ?string $comment = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New Qualification Verification Task Assigned',
        );
    }

    public function content(): Content
    {
        $id = $this->qualification->id;

        return new Content(
            markdown: 'emails.verification.qualification-assigned',
            with: [
                'qualification' => $this->qualification,
                'application' => $this->qualification->application,
                'assignedBy' => $this->assignedBy,
                'assignedTo' => $this->assignedTo,
                'comment' => $this->comment,
                'adminUrl' => url("/admin/verification/qualifications/{$id}"),
            ],
        );
    }
}
