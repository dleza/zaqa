<?php

namespace App\Domain\Verification\Listeners;

use App\Domain\Notifications\OutboundMailService;
use App\Domain\Verification\Events\QualificationAssignedToLevel2Reviewer;
use App\Mail\Verification\QualificationAssignedToLevel2ReviewerMail;
use App\Support\Notifications\NotificationQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendQualificationAssignedToLevel2Notification implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public function __construct()
    {
        $this->onQueue(NotificationQueue::listeners());
    }

    public function handle(QualificationAssignedToLevel2Reviewer $event): void
    {
        $mail = app(OutboundMailService::class);
        $assignee = $event->assignedTo;
        $qualification = $event->qualification->loadMissing('application', 'country', 'awardingInstitution', 'qualificationTypeMaster');
        $application = $qualification->application;

        $email = trim((string) ($assignee->email ?? ''));
        if ($email === '') {
            return;
        }

        $mail->queue(
            mailable: new QualificationAssignedToLevel2ReviewerMail(
                qualification: $qualification,
                assignedBy: $event->assignedBy,
                assignedTo: $assignee,
                category: $event->category,
            ),
            to: $email,
            logContext: [
                'user_id' => $assignee->id,
                'application_id' => $application->id,
                'email' => $email,
                'subject' => 'Level 2 Qualification Review Task Assigned',
                'template_key' => 'verification_assigned_level2',
            ],
        );
    }
}
