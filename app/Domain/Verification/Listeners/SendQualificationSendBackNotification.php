<?php

namespace App\Domain\Verification\Listeners;

use App\Domain\Notifications\OutboundMailService;
use App\Domain\Notifications\OutboundSmsService;
use App\Domain\Verification\Events\QualificationSentBackToApplicant;
use App\Mail\Verification\QualificationSentBackToApplicantMail;
use App\Support\Notifications\NotificationQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendQualificationSendBackNotification implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public function __construct()
    {
        $this->onQueue(NotificationQueue::listeners());
    }

    public function handle(QualificationSentBackToApplicant $event): void
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
                mailable: new QualificationSentBackToApplicantMail(
                    qualification: $event->qualification,
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
                    'subject' => 'ZAQA: Qualification amendment required',
                    'template_key' => 'verification_qualification_sent_back',
                ],
            );
        }

        $phone = trim((string) ($user->phone_primary ?? ''));
        if ($phone !== '') {
            $sms->queueTemplate(
                templateKey: 'qualification_sent_back',
                placeholders: [
                    'application_number' => (string) $application->application_number,
                ],
                phone: $phone,
                userId: $user->id,
                applicationId: $application->id,
            );
        }
    }
}
