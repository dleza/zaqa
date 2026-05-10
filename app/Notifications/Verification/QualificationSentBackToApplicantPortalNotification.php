<?php

namespace App\Notifications\Verification;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class QualificationSentBackToApplicantPortalNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $qualificationId,
        public readonly int $applicationId,
        public readonly string $applicationReference,
        public readonly string $qualificationTitle,
        public readonly ?string $awardingInstitution,
        public readonly string $actorName,
        public readonly string $comment,
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
        return 'verification.qualification_sent_back_to_applicant';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $excerpt = trim(preg_replace('/\\s+/', ' ', $this->comment) ?? '');
        if (mb_strlen($excerpt) > 240) {
            $excerpt = mb_substr($excerpt, 0, 237).'…';
        }

        return [
            'title' => 'Qualification amendment required',
            'message' => "ZAQA has returned a qualification for amendments on application {$this->applicationReference}. Open to view the comment and update your details.",
            'link_url' => "/applicant/applications/{$this->applicationId}/qualifications/{$this->qualificationId}/amend",
            'application_id' => $this->applicationId,
            'application_reference' => $this->applicationReference,
            'qualification_id' => $this->qualificationId,
            'qualification_title' => $this->qualificationTitle,
            'awarding_institution' => $this->awardingInstitution,
            'actor_name' => $this->actorName,
            'comment_excerpt' => $excerpt,
            'comment' => $this->comment,
        ];
    }
}

