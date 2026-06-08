<?php

namespace App\Domain\Verification\Listeners;

use App\Domain\Notifications\OutboundMailService;
use App\Domain\Verification\Events\ApplicationLevel1Completed;
use App\Mail\Verification\ApplicationLevel1CompletedMail;
use App\Support\Notifications\NotificationQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendLevel1CompletedNotification implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public function __construct()
    {
        $this->onQueue(NotificationQueue::listeners());
    }

    public function handle(ApplicationLevel1Completed $event): void
    {
        $mail = app(OutboundMailService::class);
        $recipient = $event->assignedBy;
        $email = trim((string) ($recipient->email ?? ''));
        if ($email === '') {
            return;
        }

        $mail->queue(
            mailable: new ApplicationLevel1CompletedMail(
                application: $event->application,
                level1Actor: $event->level1Actor,
                assignedBy: $recipient,
                findings: $event->findings,
            ),
            to: $email,
            logContext: [
                'user_id' => $recipient->id,
                'application_id' => $event->application->id,
                'email' => $email,
                'subject' => 'ZAQA: Level 1 review completed',
                'template_key' => 'verification_level1_completed',
            ],
        );
    }
}
