<?php

namespace App\Http\Controllers\Admin\Verification\Concerns;

use App\Models\Qualification;

trait MapsVerificationAssignmentQueueRows
{
    /**
     * @return array<string, mixed>
     */
    protected function mapVerificationAssignmentQueueRow(Qualification $q): array
    {
        $deadline = $q->service_deadline_at ?? $q->application?->service_deadline_at;

        return [
            'id' => $q->id,
            'verification_reference_number' => $q->verification_reference_number,
            'verification_state' => $q->verification_state?->value ?? (string) $q->verification_state,
            'qualification_title' => $q->title_of_qualification,
            'qualification_type' => $q->qualificationTypeMaster?->name,
            'service_deadline_at' => optional($deadline)?->toIso8601String(),
            'assigned_verifier' => $q->assignedVerifier?->name,
            'level2_review_owner' => $q->level2ReviewOwner?->name,
            'application' => [
                'id' => $q->application?->id,
                'application_number' => $q->application?->application_number,
                'submitted_at' => optional($q->application?->submitted_at)?->toIso8601String(),
            ],
            'applicant_name' => $q->application?->metadata['verification_subject']['full_name'] ?? $q->application?->applicant?->name,
            'holder_name' => $q->qualification_holder_name ?: ($q->application?->metadata['verification_subject']['full_name'] ?? null),
            'country_of_award' => $q->country?->name ?? $q->country_name_other,
            'awarding_institution' => $q->awardingInstitution?->name ?? $q->awarding_institution_name_other ?? $q->awarding_institution_name,
            'is_foreign' => (bool) $q->is_foreign_qualification,
            'can_assign_level2' => ($q->verification_state?->value ?? (string) $q->verification_state) === 'under_level2_review',
        ];
    }
}
