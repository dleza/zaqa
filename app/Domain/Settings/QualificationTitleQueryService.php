<?php

namespace App\Domain\Settings;

use App\Models\QualificationTitle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class QualificationTitleQueryService
{
    public function institutionHasActiveLinkedTitles(?int $awardingInstitutionId): bool
    {
        if (! $awardingInstitutionId || $awardingInstitutionId < 1) {
            return false;
        }

        return QualificationTitle::query()
            ->active()
            ->whereHas('awardingInstitutions', fn (Builder $q) => $q->whereKey($awardingInstitutionId))
            ->exists();
    }

    /**
     * @return Builder<QualificationTitle>
     */
    public function applicantTitlesQuery(?int $awardingInstitutionId, ?int $qualificationTypeId = null): Builder
    {
        $query = QualificationTitle::query()->active()->ordered();

        if ($qualificationTypeId && $qualificationTypeId > 0) {
            $query->where(function (Builder $q) use ($qualificationTypeId) {
                $q->whereNull('qualification_type_id')
                    ->orWhere('qualification_type_id', $qualificationTypeId);
            });
        }

        if ($awardingInstitutionId && $awardingInstitutionId > 0 && $this->institutionHasActiveLinkedTitles($awardingInstitutionId)) {
            $query->whereHas('awardingInstitutions', fn (Builder $q) => $q->whereKey($awardingInstitutionId));
        }

        return $query;
    }

    /**
     * @return Collection<int, QualificationTitle>
     */
    public function searchForApplicant(
        ?int $awardingInstitutionId,
        ?string $search = null,
        ?int $qualificationTypeId = null,
        int $limit = 30,
    ): Collection {
        if (! $awardingInstitutionId || $awardingInstitutionId < 1) {
            return collect();
        }

        $query = $this->applicantTitlesQuery($awardingInstitutionId, $qualificationTypeId);

        $search = trim((string) $search);
        if ($search !== '') {
            $normalized = QualificationTitle::normalizeName($search);
            if ($normalized !== '') {
                $query->where(function (Builder $q) use ($normalized, $search) {
                    $q->where('name_normalized', 'like', '%'.$normalized.'%')
                        ->orWhere('name', 'like', '%'.$search.'%');
                });
            } else {
                $query->where('name', 'like', '%'.$search.'%');
            }
        }

        return $query->limit(max(1, min($limit, 50)))->get();
    }

    public function isTitleAllowedForApplicant(QualificationTitle $title, ?int $awardingInstitutionId): bool
    {
        if (! $title->is_active) {
            return false;
        }

        if (! $awardingInstitutionId || $awardingInstitutionId < 1) {
            return false;
        }

        if (! $this->institutionHasActiveLinkedTitles($awardingInstitutionId)) {
            return true;
        }

        return $title->awardingInstitutions()->whereKey($awardingInstitutionId)->exists();
    }
}
