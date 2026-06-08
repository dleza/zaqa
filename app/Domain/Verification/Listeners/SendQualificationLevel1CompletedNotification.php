<?php

namespace App\Domain\Verification\Listeners;

use App\Domain\Notifications\OutboundMailService;
use App\Domain\Verification\Events\QualificationLevel1Completed;
use App\Mail\Verification\QualificationLevel1CompletedMail;
use App\Support\Notifications\NotificationQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendQualificationLevel1CompletedNotification implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public function __construct()
    {
        $this->onQueue(NotificationQueue::listeners());
    }

    public function handle(QualificationLevel1Completed $event): void
    {
        $mail = app(OutboundMailService::class);
        $recipient = $event->assignedBy;
        $email = trim((string) ($recipient->email ?? ''));
        if ($email === '') {
            return;
        }

        $mail->queue(
            mailable: new QualificationLevel1CompletedMail(
                qualification: $event->qualification->fresh(['application', 'country', 'awardingInstitution', 'qualificationTypeMaster']),
                level1Actor: $event->level1Actor,
                assignedBy: $recipient,
                findings: $event->findings,
            ),
            to: $email,
            logContext: [
                'user_id' => $recipient->id,
                'application_id' => $event->qualification->application_id,
                'email' => $email,
                'subject' => 'Qualification Review Submitted for Further Action',
                'template_key' => 'verification_qualification_level1_completed',
            ],
        );
    }
}
