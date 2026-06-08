<?php

namespace App\Domain\Applications\Listeners;

use App\Domain\Applications\Events\ApplicationSubmitted;
use App\Domain\Notifications\OutboundMailService;
use App\Domain\Notifications\OutboundSmsService;
use App\Mail\ApplicationSubmittedMail;
use App\Support\Notifications\NotificationQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendApplicationSubmittedNotification implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public function __construct()
    {
        $this->onQueue(NotificationQueue::listeners());
    }

    public function handle(ApplicationSubmitted $event): void
    {
        $mail = app(OutboundMailService::class);
        $sms = app(OutboundSmsService::class);
        $user = $event->actor;
        $application = $event->application->fresh(['qualifications']);
        $trackingUrl = route('applicant.applications.track', ['application' => $application->id]);

        $qualificationReferences = $application->qualifications
            ->sortBy('id')
            ->map(fn ($q) => [
                'title' => (string) ($q->title_of_qualification ?: 'Qualification'),
                'reference' => (string) ($q->verification_reference_number ?? ''),
            ])
            ->filter(fn (array $row) => $row['reference'] !== '')
            ->values()
            ->all();

        $subject = $event->isResubmission
            ? 'ZAQA application resubmitted'
            : 'ZAQA application submitted';

        $emailSent = false;
        $email = trim((string) ($user->email ?? ''));
        if ($email !== '') {
            $emailSent = $mail->queue(
                mailable: new ApplicationSubmittedMail(
                    recipientName: $user->name,
                    applicationNumber: $application->application_number,
                    trackingUrl: $trackingUrl,
                    isResubmission: $event->isResubmission,
                    qualificationReferences: $qualificationReferences,
                ),
                to: $email,
                logContext: [
                    'user_id' => $user->id,
                    'application_id' => $application->id,
                    'email' => $email,
                    'subject' => $subject,
                    'template_key' => 'application_submitted',
                ],
            );
        }

        $phone = trim((string) ($user->phone_primary ?? ''));
        if ($phone !== '') {
            $message = $event->isResubmission
                ? sprintf(
                    'ZAQA: Your application %s has been resubmitted successfully.%s',
                    $application->application_number,
                    $emailSent ? ' Qualification reference numbers are in your email.' : ''
                )
                : sprintf(
                    'ZAQA: Your application %s has been submitted successfully.%s',
                    $application->application_number,
                    $emailSent ? ' Qualification reference numbers are in your email.' : ''
                );

            $sms->send(
                phone: $phone,
                message: $message,
                messageType: $event->isResubmission ? 'application_resubmitted' : 'application_submitted',
                userId: $user->id,
                applicationId: $application->id,
            );
        }
    }
}
