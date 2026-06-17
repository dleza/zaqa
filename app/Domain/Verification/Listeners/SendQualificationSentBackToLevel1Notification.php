<?php

namespace App\Domain\Verification\Listeners;

use App\Domain\Notifications\OutboundMailService;
use App\Domain\Verification\Events\QualificationSentBackToLevel1ByLevel2;
use App\Mail\Verification\QualificationSentBackToLevel1Mail;
use App\Notifications\Verification\QualificationSentBackToLevel1PortalNotification;
use App\Support\Notifications\NotificationQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;

class SendQualificationSentBackToLevel1Notification implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public function __construct()
    {
        $this->onQueue(NotificationQueue::listeners());
    }

    public function handle(QualificationSentBackToLevel1ByLevel2 $event): void
    {
        $assignee = $event->assignedToLevel1;
        if (! $assignee) {
            return;
        }

        $qualification = $event->qualification->loadMissing('application');
        $applicationRef = (string) ($qualification->application?->application_number ?? '—');
        $title = (string) ($qualification->title_of_qualification ?? 'Qualification');
        $excerpt = Str::limit(trim($event->comment), 240);

        $assignee->notify(new QualificationSentBackToLevel1PortalNotification(
            qualificationId: (int) $qualification->id,
            applicationReference: $applicationRef,
            qualificationTitle: $title,
            sentByName: (string) ($event->sentBy->name ?? 'Level 2 officer'),
            commentExcerpt: $excerpt,
        ));

        $email = trim((string) ($assignee->email ?? ''));
        if ($email === '') {
            return;
        }

        app(OutboundMailService::class)->queue(
            mailable: new QualificationSentBackToLevel1Mail(
                qualification: $qualification,
                sentBy: $event->sentBy,
                assignedTo: $assignee,
                comment: $event->comment,
            ),
            to: $email,
            logContext: [
                'user_id' => $assignee->id,
                'application_id' => $qualification->application_id,
                'email' => $email,
                'subject' => 'Qualification Returned for Level 1 Correction',
                'template_key' => 'verification_sent_back_to_level1',
            ],
        );
    }
}
