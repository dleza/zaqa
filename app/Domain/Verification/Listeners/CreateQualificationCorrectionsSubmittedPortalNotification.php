<?php

namespace App\Domain\Verification\Listeners;

use App\Domain\Verification\Events\QualificationCorrectionsSubmitted;
use App\Notifications\Verification\QualificationCorrectionsSubmittedPortalNotification;

class CreateQualificationCorrectionsSubmittedPortalNotification
{
    public function handle(QualificationCorrectionsSubmitted $event): void
    {
        $officer = $event->returnedToOfficer;
        if (! $officer) {
            return;
        }

        $qualification = $event->qualification;
        $application = $event->application;

        $officer->notify(new QualificationCorrectionsSubmittedPortalNotification(
            qualificationId: (int) $qualification->id,
            applicationReference: (string) ($application->application_number ?? '—'),
            qualificationTitle: (string) ($qualification->title_of_qualification ?? 'Qualification'),
            holderName: trim((string) ($qualification->qualification_holder_name ?? '')) !== ''
                ? (string) $qualification->qualification_holder_name
                : null,
            applicantName: (string) ($event->applicant->name ?? 'Applicant'),
        ));
    }
}
