<?php

namespace App\Domain\Verification\Listeners;

use App\Domain\Verification\Events\QualificationAssignedToLevel2Reviewer;
use App\Notifications\Verification\QualificationAssignedToLevel2PortalNotification;

class CreateQualificationAssignedToLevel2PortalNotification
{
    public function handle(QualificationAssignedToLevel2Reviewer $event): void
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

        $event->assignedTo->notify(new QualificationAssignedToLevel2PortalNotification(
            qualificationId: (int) $qualification->id,
            applicationReference: $applicationRef,
            qualificationTitle: $qualificationTitle,
            qualificationType: $qualificationType,
            awardingInstitution: $awardingInstitution,
            categoryName: (string) ($event->category?->name ?? 'Level 2 review'),
            assignedByName: (string) ($event->assignedBy->name ?? 'System'),
        ));
    }
}
