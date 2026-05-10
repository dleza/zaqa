<?php

namespace App\Notifications\Verification;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class QualificationReassignedPortalNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $qualificationId,
        public readonly string $applicationReference,
        public readonly string $qualificationTitle,
        public readonly ?string $qualificationType,
        public readonly ?string $awardingInstitution,
        public readonly string $newAssigneeName,
        public readonly string $assignedByName,
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
        return 'verification.qualification_reassigned';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Qualification task reassigned',
            'message' => "A qualification verification task for application {$this->applicationReference} has been reassigned to {$this->newAssigneeName}.",
            'link_url' => "/admin/verification/qualifications/{$this->qualificationId}",
            'application_reference' => $this->applicationReference,
            'qualification_id' => $this->qualificationId,
            'qualification_title' => $this->qualificationTitle,
            'qualification_type' => $this->qualificationType,
            'awarding_institution' => $this->awardingInstitution,
            'new_assignee_name' => $this->newAssigneeName,
            'assigned_by_name' => $this->assignedByName,
        ];
    }
}

