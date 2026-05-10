<?php

namespace App\Domain\Verification\Listeners;

use App\Domain\Verification\Events\QualificationLevel1Completed;
use App\Notifications\Verification\QualificationLevel1CompletedPortalNotification;

class CreateQualificationLevel1CompletedPortalNotification
{
    public function handle(QualificationLevel1Completed $event): void
    {
        $qualification = $event->qualification->loadMissing('application', 'country', 'awardingInstitution', 'qualificationTypeMaster');

        $applicationRef = (string) ($qualification->application?->application_number ?? '—');
        $qualificationTitle = (string) ($qualification->title_of_qualification ?? 'Qualification');
        $fallbackType = (string) ($qualification->qualification_type ?? '');
        $qualificationType = $qualification->qualificationTypeMaster?->name ?: $fallbackType;
        $qualificationType = trim((string) $qualificationType) !== '' ? (string) $qualificationType : null;

        $awardingInstitution = $qualification->awardingInstitution?->name
            ?? $qualification->awarding_institution_name_other
            ?? $qualification->awarding_institution_name;
        $awardingInstitution = trim((string) $awardingInstitution) !== '' ? (string) $awardingInstitution : null;

        $event->assignedBy->notify(new QualificationLevel1CompletedPortalNotification(
            qualificationId: (int) $qualification->id,
            applicationReference: $applicationRef,
            qualificationTitle: $qualificationTitle,
            qualificationType: $qualificationType,
            awardingInstitution: $awardingInstitution,
            level1ActorName: (string) ($event->level1Actor->name ?? 'Level 1 officer'),
            findings: (string) $event->findings,
        ));
    }
}

