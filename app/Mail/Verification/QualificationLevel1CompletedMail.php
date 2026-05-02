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

class QualificationLevel1CompletedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Qualification $qualification,
        public readonly User $level1Actor,
        public readonly User $assignedBy,
        public readonly string $findings,
    ) {}

    public function envelope(): Envelope
    {
        $num = $this->qualification->application?->application_number ?? 'verification';

        return new Envelope(
            subject: "ZAQA: Level 1 completed qualification task ({$num})",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.verification.qualification-level1-completed',
            with: [
                'qualification' => $this->qualification,
                'application' => $this->qualification->application,
                'level1Actor' => $this->level1Actor,
                'assignedBy' => $this->assignedBy,
                'findings' => $this->findings,
                'adminUrl' => url("/admin/verification/qualifications/{$this->qualification->id}"),
            ],
        );
    }
}
