<?php

namespace App\Notifications\Verification;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class QualificationAssignedPortalNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $qualificationId,
        public readonly string $applicationReference,
        public readonly string $qualificationTitle,
        public readonly ?string $qualificationType,
        public readonly ?string $awardingInstitution,
        public readonly string $assignedByName,
        public readonly ?string $comment = null,
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
        return 'verification.qualification_assigned';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Qualification task assigned',
            'message' => "You have been assigned a qualification verification task for application {$this->applicationReference}.",
            'link_url' => "/admin/verification/qualifications/{$this->qualificationId}",
            'application_reference' => $this->applicationReference,
            'qualification_id' => $this->qualificationId,
            'qualification_title' => $this->qualificationTitle,
            'qualification_type' => $this->qualificationType,
            'awarding_institution' => $this->awardingInstitution,
            'assigned_by_name' => $this->assignedByName,
            'comment' => $this->comment,
        ];
    }
}

