<?php

namespace App\Domain\Verification\Listeners;

use App\Domain\Notifications\OutboundMailService;
use App\Domain\Notifications\OutboundSmsService;
use App\Domain\Verification\Events\QualificationAssignedToVerifier;
use App\Mail\Verification\QualificationAssignedToVerifierMail;
use App\Support\Notifications\NotificationQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendAssignmentNotification implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public function __construct()
    {
        $this->onQueue(NotificationQueue::listeners());
    }

    public function handle(QualificationAssignedToVerifier $event): void
    {
        $mail = app(OutboundMailService::class);
        $sms = app(OutboundSmsService::class);
        $assignee = $event->assignedTo;
        $qualification = $event->qualification->loadMissing('application', 'country', 'awardingInstitution', 'qualificationTypeMaster');
        $application = $qualification->application;

        $comment = $event->comment;
        if ($comment === null && (string) ($qualification->assignment_source ?? '') === 'auto') {
            if ((bool) ($qualification->is_foreign_qualification ?? false)) {
                $country = $qualification->country?->name ?? $qualification->country_name_other;
                $country = trim((string) $country) !== '' ? (string) $country : null;
                $comment = $country ? "Automatically assigned (Category: {$country})." : 'Automatically assigned (Category: foreign country).';
            } else {
                $inst = $qualification->awardingInstitution?->name
                    ?? $qualification->awarding_institution_name_other
                    ?? $qualification->awarding_institution_name;
                $inst = trim((string) $inst) !== '' ? (string) $inst : null;
                $comment = $inst ? "Automatically assigned (Category: {$inst})." : 'Automatically assigned (Category: local institution).';
            }
        }

        $email = trim((string) ($assignee->email ?? ''));
        if ($email !== '') {
            $mail->queue(
                mailable: new QualificationAssignedToVerifierMail(
                    qualification: $qualification,
                    assignedBy: $event->assignedBy,
                    assignedTo: $assignee,
                    comment: $comment,
                ),
                to: $email,
                logContext: [
                    'user_id' => $assignee->id,
                    'application_id' => $application->id,
                    'email' => $email,
                    'subject' => 'New Qualification Verification Task Assigned',
                    'template_key' => 'verification_assigned',
                ],
            );
        }

        $phone = trim((string) ($assignee->phone_primary ?? ''));
        if ($phone !== '') {
            $sms->queueTemplate(
                templateKey: 'verification_assigned',
                placeholders: [
                    'application_number' => (string) $application->application_number,
                ],
                phone: $phone,
                userId: $assignee->id,
                applicationId: $application->id,
            );
        }
    }
}
