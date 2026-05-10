<?php

namespace App\Domain\Verification\Listeners;

use App\Domain\Verification\Events\QualificationSentBackToApplicant;
use App\Notifications\Verification\QualificationSentBackToApplicantPortalNotification;

class CreateQualificationSendBackApplicantPortalNotification
{
    public function handle(QualificationSentBackToApplicant $event): void
    {
        $application = $event->application;
        $applicant = $application->applicant()->first();
        if (! $applicant) {
            return;
        }

        $qualification = $event->qualification->loadMissing('awardingInstitution');

        $applicationRef = (string) ($application->application_number ?? '—');
        $qualificationTitle = (string) ($qualification->title_of_qualification ?? 'Qualification');

        $awardingInstitution = $qualification->awardingInstitution?->name
            ?? $qualification->awarding_institution_name_other
            ?? $qualification->awarding_institution_name;
        $awardingInstitution = trim((string) $awardingInstitution) !== '' ? (string) $awardingInstitution : null;

        $applicant->notify(new QualificationSentBackToApplicantPortalNotification(
            qualificationId: (int) $qualification->id,
            applicationId: (int) $application->id,
            applicationReference: $applicationRef,
            qualificationTitle: $qualificationTitle,
            awardingInstitution: $awardingInstitution,
            actorName: (string) ($event->actor->name ?? 'ZAQA officer'),
            comment: (string) $event->comment,
        ));
    }
}

