<?php

namespace App\Domain\Verification;

use App\Enums\QualificationTitleSource;
use App\Models\Qualification;
use App\Models\QualificationTitle;

class QualificationTitleCatalogStatus
{
    /**
     * @return array{
     *     is_auto_verified: bool,
     *     is_new_title: bool,
     *     is_applicant_other: bool,
     *     show_new_title_prompt: bool,
     *     will_promote_on_issue: bool,
     *     catalog_title_id: int|null,
     *     catalog_match_name: string|null,
     *     resolved_title: string|null,
     * }
     */
    public function forQualification(Qualification $qualification): array
    {
        $isAutoVerified = $this->isAutoVerified($qualification);
        $resolvedTitle = $this->resolveTitleText($qualification);
        $catalogMatch = $this->findCatalogMatch($resolvedTitle);
        $isNewTitle = $catalogMatch === null && $resolvedTitle !== '';

        return [
            'is_auto_verified' => $isAutoVerified,
            'is_new_title' => $isNewTitle,
            'is_applicant_other' => $qualification->qualification_title_source === QualificationTitleSource::Other,
            'show_new_title_prompt' => ! $isAutoVerified && $isNewTitle,
            'will_promote_on_issue' => $isNewTitle,
            'catalog_title_id' => $qualification->qualification_title_id
                ? (int) $qualification->qualification_title_id
                : ($catalogMatch?->id),
            'catalog_match_name' => $catalogMatch?->name,
            'resolved_title' => $resolvedTitle !== '' ? $resolvedTitle : null,
        ];
    }

    public function isAutoVerified(Qualification $qualification): bool
    {
        if ($qualification->auto_verified_at !== null && $qualification->learner_record_id) {
            return true;
        }

        return in_array((string) ($qualification->verification_source ?? ''), [
            'internal_learner_record',
            'institution_api',
        ], true);
    }

    public function isNewTitle(Qualification $qualification): bool
    {
        if ($qualification->qualification_title_id) {
            return false;
        }

        $resolvedTitle = $this->resolveTitleText($qualification);

        return $resolvedTitle !== '' && $this->findCatalogMatch($resolvedTitle) === null;
    }

    public function resolveTitleText(Qualification $qualification): string
    {
        $verified = trim((string) ($qualification->verified_qualification_title ?? ''));
        if ($verified !== '') {
            return $verified;
        }

        $applicantEntered = trim((string) ($qualification->applicant_entered_qualification_title ?? ''));

        return $applicantEntered !== ''
            ? $applicantEntered
            : trim((string) ($qualification->title_of_qualification ?? ''));
    }

    private function findCatalogMatch(string $title): ?QualificationTitle
    {
        $normalized = QualificationTitle::normalizeName($title);
        if ($normalized === '') {
            return null;
        }

        return QualificationTitle::query()
            ->where('name_normalized', $normalized)
            ->first();
    }
}
