<?php

namespace App\Domain\Verification\Listeners;

use App\Domain\Verification\Events\QualificationAssignedToVerifier;
use App\Notifications\Verification\QualificationAssignedPortalNotification;
use App\Notifications\Verification\QualificationReassignedPortalNotification;

class CreateQualificationAssignmentPortalNotification
{
    public function handle(QualificationAssignedToVerifier $event): void
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

        $comment = $event->comment;
        if ($comment === null && (string) ($qualification->assignment_source ?? '') === 'auto') {
            if ((bool) ($qualification->is_foreign_qualification ?? false)) {
                $country = $qualification->country?->name ?? $qualification->country_name_other;
                $country = trim((string) $country) !== '' ? (string) $country : null;
                $comment = $country ? "Automatically assigned (Category: {$country})." : 'Automatically assigned (Category: foreign country).';
            } else {
                $inst = $awardingInstitution;
                $comment = $inst ? "Automatically assigned (Category: {$inst})." : 'Automatically assigned (Category: local institution).';
            }
        }

        $event->assignedTo->notify(new QualificationAssignedPortalNotification(
            qualificationId: (int) $qualification->id,
            applicationReference: $applicationRef,
            qualificationTitle: $qualificationTitle,
            qualificationType: $qualificationType,
            awardingInstitution: $awardingInstitution,
            assignedByName: (string) ($event->assignedBy->name ?? 'Level 2 officer'),
            comment: $comment,
        ));

        // Optional: notify previous assignee that the task moved away from them.
        if ($event->previousAssignee && (int) $event->previousAssignee->id !== (int) $event->assignedTo->id) {
            $event->previousAssignee->notify(new QualificationReassignedPortalNotification(
                qualificationId: (int) $qualification->id,
                applicationReference: $applicationRef,
                qualificationTitle: $qualificationTitle,
                qualificationType: $qualificationType,
                awardingInstitution: $awardingInstitution,
                newAssigneeName: (string) ($event->assignedTo->name ?? 'a Level 1 officer'),
                assignedByName: (string) ($event->assignedBy->name ?? 'Level 2 officer'),
            ));
        }
    }
}
