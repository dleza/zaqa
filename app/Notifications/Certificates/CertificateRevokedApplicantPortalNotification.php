<?php

namespace App\Notifications\Certificates;

use App\Models\QualificationCertificate;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class CertificateRevokedApplicantPortalNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $applicationId,
        public readonly string $applicationReference,
        public readonly int $qualificationId,
        public readonly string $qualificationTitle,
        public readonly string $certificateType,
        public readonly ?string $publicNote,
        public readonly string $linkUrl,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return 'certificates.certificate_revoked';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $isRejection = $this->certificateType === QualificationCertificate::TYPE_REJECTION;
        $title = $isRejection ? 'Rejection notice recalled' : 'Verification certificate recalled';

        $message = $isRejection
            ? 'Your rejection notice'
            : 'Your verification certificate';

        if ($this->qualificationTitle !== '') {
            $message .= ' for '.$this->qualificationTitle;
        }

        $message .= ' has been recalled by ZAQA and is no longer valid.';

        if ($this->publicNote !== null && trim($this->publicNote) !== '') {
            $message .= ' '.$this->publicNote;
        }

        return [
            'title' => $title,
            'message' => $message,
            'link_url' => $this->linkUrl,
            'application_id' => $this->applicationId,
            'application_reference' => $this->applicationReference,
            'qualification_id' => $this->qualificationId,
            'qualification_title' => $this->qualificationTitle,
            'certificate_type' => $this->certificateType,
            'public_note' => $this->publicNote,
        ];
    }
}
