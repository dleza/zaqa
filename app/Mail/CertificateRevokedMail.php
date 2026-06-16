<?php

namespace App\Mail;

use App\Models\Application;
use App\Models\Qualification;
use App\Models\QualificationCertificate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CertificateRevokedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Application $application,
        public Qualification $qualification,
        public QualificationCertificate $certificate,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->certificate->isRejectionCertificate()
            ? 'ZAQA rejection notice recalled - '.$this->application->application_number
            : 'ZAQA verification certificate recalled - '.$this->application->application_number;

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $qualificationTitle = trim((string) ($this->qualification->title_of_qualification ?? ''));
        $isRejection = $this->certificate->isRejectionCertificate();

        $headline = $isRejection ? 'Rejection notice recalled' : 'Verification certificate recalled';

        $body = $isRejection
            ? 'Your rejection notice'
            : 'Your verification certificate';

        if ($qualificationTitle !== '') {
            $body .= ' for '.$qualificationTitle;
        }

        $body .= ' has been recalled by the Zambia Qualifications Authority and is no longer valid.';

        $publicNote = trim((string) ($this->certificate->revocation_public_note ?? ''));
        if ($publicNote !== '') {
            $body .= ' '.$publicNote;
        }

        return new Content(
            view: 'emails.certificate-revoked',
            with: [
                'applicationNumber' => $this->application->application_number,
                'headline' => $headline,
                'body' => $body,
                'portalUrl' => route('applicant.applications.show', $this->application),
            ],
        );
    }
}
