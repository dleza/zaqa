<?php

namespace App\Notifications\Verification;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class QualificationLevel1CompletedPortalNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $qualificationId,
        public readonly string $applicationReference,
        public readonly string $qualificationTitle,
        public readonly ?string $qualificationType,
        public readonly ?string $awardingInstitution,
        public readonly string $level1ActorName,
        public readonly string $findings,
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
        return 'verification.qualification_level1_completed';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $excerpt = trim(preg_replace('/\\s+/', ' ', $this->findings) ?? '');
        if (mb_strlen($excerpt) > 280) {
            $excerpt = mb_substr($excerpt, 0, 277).'…';
        }

        return [
            'title' => 'Level 1 review completed',
            'message' => "Level 1 review has been completed for qualification {$this->qualificationTitle}. Please review the recommendation.",
            'link_url' => "/admin/verification/qualifications/{$this->qualificationId}",
            'application_reference' => $this->applicationReference,
            'qualification_id' => $this->qualificationId,
            'qualification_title' => $this->qualificationTitle,
            'qualification_type' => $this->qualificationType,
            'awarding_institution' => $this->awardingInstitution,
            'level1_actor_name' => $this->level1ActorName,
            'findings_excerpt' => $excerpt,
            'findings' => $this->findings,
        ];
    }
}

