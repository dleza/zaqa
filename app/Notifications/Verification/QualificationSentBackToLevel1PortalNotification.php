<?php

namespace App\Notifications\Verification;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class QualificationSentBackToLevel1PortalNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $qualificationId,
        public readonly string $applicationReference,
        public readonly string $qualificationTitle,
        public readonly string $sentByName,
        public readonly string $commentExcerpt,
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
        return 'verification.qualification_sent_back_to_level1';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Returned for Level 1 correction',
            'message' => "Level 2 has returned a qualification to you for correction ({$this->applicationReference}).",
            'link_url' => "/admin/verification/qualifications/{$this->qualificationId}",
            'application_reference' => $this->applicationReference,
            'qualification_id' => $this->qualificationId,
            'qualification_title' => $this->qualificationTitle,
            'sent_by_name' => $this->sentByName,
            'comment_excerpt' => $this->commentExcerpt,
        ];
    }
}
