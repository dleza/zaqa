<?php

namespace App\Notifications\Verification;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class QualificationAssignedToLevel2PortalNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $qualificationId,
        public readonly string $applicationReference,
        public readonly string $qualificationTitle,
        public readonly ?string $qualificationType,
        public readonly ?string $awardingInstitution,
        public readonly string $categoryName,
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
        return 'verification.qualification_assigned_level2';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Level 2 review task assigned',
            'message' => "You have been assigned a Level 2 review for application {$this->applicationReference}.",
            'link_url' => "/admin/verification/qualifications/{$this->qualificationId}",
            'application_reference' => $this->applicationReference,
            'qualification_id' => $this->qualificationId,
            'qualification_title' => $this->qualificationTitle,
            'qualification_type' => $this->qualificationType,
            'awarding_institution' => $this->awardingInstitution,
            'assignment_category' => $this->categoryName,
            'assigned_by_name' => $this->assignedByName,
        ];
    }
}
