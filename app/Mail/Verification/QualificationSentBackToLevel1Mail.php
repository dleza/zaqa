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
use Illuminate\Support\Str;

class QualificationSentBackToLevel1Mail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Qualification $qualification,
        public readonly User $sentBy,
        public readonly User $assignedTo,
        public readonly string $comment,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Qualification Returned for Level 1 Correction',
        );
    }

    public function content(): Content
    {
        $id = $this->qualification->id;

        return new Content(
            markdown: 'emails.verification.qualification-sent-back-to-level1',
            with: [
                'qualification' => $this->qualification,
                'application' => $this->qualification->application,
                'sentBy' => $this->sentBy,
                'assignedTo' => $this->assignedTo,
                'comment' => $this->comment,
                'commentExcerpt' => Str::limit($this->comment, 240),
                'adminUrl' => url("/admin/verification/qualifications/{$id}"),
            ],
        );
    }
}
