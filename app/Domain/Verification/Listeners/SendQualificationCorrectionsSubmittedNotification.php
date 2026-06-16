<?php

namespace App\Domain\Verification\Listeners;

use App\Domain\Notifications\OutboundMailService;
use App\Domain\Verification\Events\QualificationCorrectionsSubmitted;
use App\Mail\Verification\QualificationCorrectionsSubmittedMail;
use App\Support\Notifications\NotificationQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendQualificationCorrectionsSubmittedNotification implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public function __construct()
    {
        $this->onQueue(NotificationQueue::listeners());
    }

    public function handle(QualificationCorrectionsSubmitted $event): void
    {
        $officer = $event->returnedToOfficer;
        if (! $officer) {
            return;
        }

        $email = trim((string) ($officer->email ?? ''));
        if ($email === '') {
            return;
        }

        $qualification = $event->qualification->loadMissing('application');
        $application = $event->application;

        app(OutboundMailService::class)->queue(
            mailable: new QualificationCorrectionsSubmittedMail(
                qualification: $qualification,
                application: $application,
                applicant: $event->applicant,
                officer: $officer,
            ),
            to: $email,
            logContext: [
                'user_id' => $officer->id,
                'application_id' => $application->id,
                'email' => $email,
                'subject' => 'ZAQA: Applicant corrections submitted',
                'template_key' => 'verification_qualification_corrections_submitted',
            ],
        );
    }
}
