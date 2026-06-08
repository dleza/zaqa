<?php

namespace App\Domain\Verification\Listeners;

use App\Domain\Notifications\OutboundMailService;
use App\Domain\Notifications\OutboundSmsService;
use App\Domain\Verification\Events\ApplicationSentBackToApplicant;
use App\Mail\Verification\ApplicationSentBackToApplicantMail;
use App\Support\Notifications\NotificationQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendSendBackNotification implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public function __construct()
    {
        $this->onQueue(NotificationQueue::listeners());
    }

    public function handle(ApplicationSentBackToApplicant $event): void
    {
        $mail = app(OutboundMailService::class);
        $sms = app(OutboundSmsService::class);
        $application = $event->application;
        $user = $application->applicant()->first();
        if (! $user) {
            return;
        }

        $email = trim((string) ($user->email ?? ''));
        if ($email !== '') {
            $mail->queue(
                mailable: new ApplicationSentBackToApplicantMail(
                    application: $application,
                    applicant: $user,
                    actor: $event->actor,
                    comment: $event->comment,
                ),
                to: $email,
                logContext: [
                    'user_id' => $user->id,
                    'application_id' => $application->id,
                    'email' => $email,
                    'subject' => 'ZAQA: Application sent back for amendments',
                    'template_key' => 'verification_sent_back',
                ],
            );
        }

        $phone = trim((string) ($user->phone_primary ?? ''));
        if ($phone !== '') {
            $sms->send(
                phone: $phone,
                message: sprintf(
                    'ZAQA: Application %s was sent back for amendments. Please login to view the comment and resubmit.',
                    $application->application_number
                ),
                messageType: 'verification_sent_back',
                userId: $user->id,
                applicationId: $application->id,
            );
        }
    }
}
