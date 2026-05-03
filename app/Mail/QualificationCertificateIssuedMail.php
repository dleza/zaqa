<?php

namespace App\Mail;

use App\Models\Application;
use App\Models\Qualification;
use App\Models\QualificationCertificate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class QualificationCertificateIssuedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Qualification $qualification,
        public Application $application,
        public QualificationCertificate $certificate,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'ZAQA Certificate Issued - '.$this->certificate->certificate_number,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.qualification-certificate-issued',
            with: [
                'applicationNumber' => $this->application->application_number,
                'qualificationTitle' => (string) ($this->qualification->title_of_qualification ?? ''),
                'certificateNumber' => $this->certificate->certificate_number,
                'downloadUrl' => route('applicant.applications.qualifications.certificate.download', [
                    'application' => $this->application,
                    'qualification' => $this->qualification,
                ]),
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromStorageDisk('local', $this->certificate->file_path)
                ->as('ZAQA-Certificate-'.$this->certificate->certificate_number.'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
