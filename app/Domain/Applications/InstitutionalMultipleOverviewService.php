<?php

namespace App\Domain\Applications;

use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\Qualification;
use App\Support\Applications\ApplicationSubmissionMode;

class InstitutionalMultipleOverviewService
{
    /**
     * @return array{
     *   total_qualifications:int,
     *   in_review:int,
     *   returned_for_correction:int,
     *   completed:int,
     *   rows: array<int, array<string, mixed>>
     * }
     */
    public function build(Application $application): array
    {
        $application->loadMissing([
            'qualifications.awardingInstitution',
            'qualifications.country',
        ]);

        $qualifications = $application->qualifications;

        $counts = [
            'total_qualifications' => $qualifications->count(),
            'in_review' => 0,
            'returned_for_correction' => 0,
            'completed' => 0,
        ];

        $rows = [];

        foreach ($qualifications as $qualification) {
            /** @var Qualification $qualification */
            $bucket = $this->summaryBucketForQualification($qualification);
            if ($bucket !== null) {
                $counts[$bucket]++;
            }

            $rows[] = [
                'id' => $qualification->id,
                'holder_name' => trim((string) ($qualification->qualification_holder_name ?? '')) ?: '—',
                'qualification_title' => $qualification->title_of_qualification,
                'awarding_institution' => $qualification->awardingInstitution?->name
                    ?? $qualification->awarding_institution_name_other
                    ?? $qualification->awarding_institution_name,
                'verification_reference_number' => $qualification->verification_reference_number,
                'verification_state' => $qualification->verification_state?->value ?? (string) $qualification->verification_state,
                'status_label' => $this->statusLabel($qualification),
                'action_href' => $this->actionHref($application, $qualification),
                'action_label' => $this->actionLabel($qualification),
            ];
        }

        return array_merge($counts, ['rows' => $rows]);
    }

    public function isInstitutionalMultiple(Application $application): bool
    {
        return ApplicationSubmissionMode::isInstitutionalMultiple($application);
    }

    /**
     * @return 'in_review'|'returned_for_correction'|'completed'|null
     */
    private function summaryBucketForQualification(Qualification $qualification): ?string
    {
        $state = $qualification->verification_state;

        if ($state === VerificationState::ReturnedToApplicant) {
            return 'returned_for_correction';
        }

        if ($this->isCompletedState($state)) {
            return 'completed';
        }

        if ($this->isInReviewState($state)) {
            return 'in_review';
        }

        return null;
    }

    private function isCompletedState(?VerificationState $state): bool
    {
        return in_array($state, [
            VerificationState::ApprovedForCertificate,
            VerificationState::Rejected,
            VerificationState::CertificateIssued,
            VerificationState::Closed,
        ], true);
    }

    private function isInReviewState(?VerificationState $state): bool
    {
        if ($state === null) {
            return false;
        }

        if ($this->isCompletedState($state) || $state === VerificationState::ReturnedToApplicant) {
            return false;
        }

        return in_array($state, [
            VerificationState::AwaitingAutoVerification,
            VerificationState::AwaitingAssignment,
            VerificationState::AssignedToLevel1,
            VerificationState::UnderLevel1Review,
            VerificationState::UnderLevel2Review,
            VerificationState::AutoVerifiedPendingLevel2,
        ], true);
    }

    private function statusLabel(Qualification $qualification): string
    {
        $state = $qualification->verification_state;

        if ($state === VerificationState::ReturnedToApplicant) {
            return 'Returned for correction';
        }

        return match ($state) {
            VerificationState::ApprovedForCertificate => 'Approved',
            VerificationState::Rejected => 'Rejected',
            VerificationState::CertificateIssued => 'Certificate issued',
            VerificationState::Closed => 'Closed',
            VerificationState::AwaitingAutoVerification,
            VerificationState::AwaitingAssignment,
            VerificationState::AssignedToLevel1,
            VerificationState::UnderLevel1Review,
            VerificationState::UnderLevel2Review,
            VerificationState::AutoVerifiedPendingLevel2 => 'In review',
            default => 'In review',
        };
    }

    private function actionHref(Application $application, Qualification $qualification): ?string
    {
        if ($qualification->verification_state === VerificationState::ReturnedToApplicant) {
            return route('applicant.applications.multiple.qualifications.edit', [
                'application' => $application->id,
                'qualification' => $qualification->id,
            ]);
        }

        return route('applicant.applications.show', $application);
    }

    private function actionLabel(Qualification $qualification): string
    {
        return $qualification->verification_state === VerificationState::ReturnedToApplicant ? 'Correct' : 'View';
    }
}
