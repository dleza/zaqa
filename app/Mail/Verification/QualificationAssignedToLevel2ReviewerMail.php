<?php

namespace App\Mail\Verification;

use App\Models\Qualification;
use App\Models\User;
use App\Models\VerificationAssignmentCategory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class QualificationAssignedToLevel2ReviewerMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Qualification $qualification,
        public readonly User $assignedBy,
        public readonly User $assignedTo,
        public readonly ?VerificationAssignmentCategory $category = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Level 2 Qualification Review Task Assigned',
        );
    }

    public function content(): Content
    {
        $id = $this->qualification->id;

        return new Content(
            markdown: 'emails.verification.qualification-assigned-level2',
            with: [
                'qualification' => $this->qualification,
                'application' => $this->qualification->application,
                'assignedBy' => $this->assignedBy,
                'assignedTo' => $this->assignedTo,
                'category' => $this->category,
                'adminUrl' => url("/admin/verification/qualifications/{$id}"),
            ],
        );
    }
}
