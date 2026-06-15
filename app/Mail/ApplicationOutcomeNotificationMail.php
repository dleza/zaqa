<?php

namespace App\Mail;

use App\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ApplicationOutcomeNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public Application $application,
        public string $outcome,
        public array $context = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectForOutcome(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.application-outcome-notification',
            with: [
                'applicationNumber' => $this->application->application_number,
                'outcome' => $this->outcome,
                'headline' => $this->headlineForOutcome(),
                'body' => $this->bodyForOutcome(),
                'portalUrl' => route('applicant.applications.show', $this->application),
            ],
        );
    }

    private function subjectForOutcome(): string
    {
        return match ($this->outcome) {
            'application_approved' => 'ZAQA application approved - '.$this->application->application_number,
            'application_rejected' => 'ZAQA application decision - '.$this->application->application_number,
            'qualification_rejected' => 'ZAQA qualification decision - '.$this->application->application_number,
            default => 'ZAQA application update - '.$this->application->application_number,
        };
    }

    private function headlineForOutcome(): string
    {
        return match ($this->outcome) {
            'application_approved' => 'Application approved',
            'application_rejected' => 'Application not approved',
            'qualification_rejected' => 'Qualification not approved',
            default => 'Application update',
        };
    }

    private function bodyForOutcome(): string
    {
        $reason = trim((string) ($this->context['reason'] ?? ''));
        $qualificationTitle = trim((string) ($this->context['qualification_title'] ?? ''));

        return match ($this->outcome) {
            'application_approved' => 'Your ZAQA application '.$this->application->application_number.' has been approved. Certificate issuance may follow where applicable.',
            'application_rejected' => $reason !== ''
                ? 'Your ZAQA application '.$this->application->application_number.' was not approved. Reason: '.$reason
                : 'Your ZAQA application '.$this->application->application_number.' was not approved.',
            'qualification_rejected' => $qualificationTitle !== '' && $reason !== ''
                ? 'A qualification item on application '.$this->application->application_number.' ('.$qualificationTitle.') was not approved. Reason: '.$reason
                : ($reason !== ''
                    ? 'A qualification item on application '.$this->application->application_number.' was not approved. Reason: '.$reason
                    : 'A qualification item on application '.$this->application->application_number.' was not approved.'),
            default => 'There is an update on ZAQA application '.$this->application->application_number.'.',
        };
    }
}
