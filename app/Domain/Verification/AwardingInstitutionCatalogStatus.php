<?php

namespace App\Domain\Verification;

use App\Models\AwardingInstitution;
use App\Models\Qualification;
use Illuminate\Support\Str;

class AwardingInstitutionCatalogStatus
{
    public function __construct(
        private readonly QualificationTitleCatalogStatus $titleCatalog,
    ) {}

    /**
     * @return array{
     *     is_applicant_other: bool,
     *     is_new_institution: bool,
     *     show_new_institution_prompt: bool,
     *     will_promote_on_issue: bool,
     *     catalog_institution_id: int|null,
     *     catalog_match_name: string|null,
     *     resolved_name: string|null,
     * }
     */
    public function forQualification(Qualification $qualification): array
    {
        $qualification->loadMissing('awardingInstitution', 'country');

        $resolvedName = $this->resolveInstitutionName($qualification);
        $isApplicantOther = $this->isApplicantOtherInstitution($qualification, $resolvedName);
        $catalogMatch = $this->findCatalogMatch($qualification, $resolvedName);
        $isNewInstitution = $isApplicantOther && $catalogMatch === null && $resolvedName !== '';

        return [
            'is_applicant_other' => $isApplicantOther,
            'is_new_institution' => $isNewInstitution,
            'show_new_institution_prompt' => ! $this->titleCatalog->isAutoVerified($qualification) && $isNewInstitution,
            'will_promote_on_issue' => $isNewInstitution,
            'catalog_institution_id' => $qualification->awarding_institution_id
                ? (int) $qualification->awarding_institution_id
                : ($catalogMatch?->id),
            'catalog_match_name' => $catalogMatch?->name,
            'resolved_name' => $resolvedName !== '' ? $resolvedName : null,
        ];
    }

    public function resolveInstitutionName(Qualification $qualification): string
    {
        if ($qualification->awardingInstitution) {
            return trim((string) $qualification->awardingInstitution->name);
        }

        $other = trim((string) ($qualification->awarding_institution_name_other ?? ''));

        return $other !== ''
            ? $other
            : trim((string) ($qualification->awarding_institution_name ?? ''));
    }

    public function isApplicantOtherInstitution(Qualification $qualification, ?string $resolvedName = null): bool
    {
        if ($qualification->awarding_institution_id) {
            return false;
        }

        $name = $resolvedName ?? $this->resolveInstitutionName($qualification);

        return $name !== '';
    }

    public function isNewInstitution(Qualification $qualification): bool
    {
        if ($qualification->awarding_institution_id) {
            return false;
        }

        $resolvedName = $this->resolveInstitutionName($qualification);

        return $resolvedName !== '' && $this->findCatalogMatch($qualification, $resolvedName) === null;
    }

    private function findCatalogMatch(Qualification $qualification, string $name): ?AwardingInstitution
    {
        $normalized = self::normalizeName($name);
        if ($normalized === '' || ! $qualification->country_id) {
            return null;
        }

        return AwardingInstitution::query()
            ->where('country_id', (int) $qualification->country_id)
            ->whereRaw('LOWER(TRIM(name)) = ?', [$normalized])
            ->first();
    }

    public static function normalizeName(string $name): string
    {
        return Str::lower(trim(preg_replace('/\s+/', ' ', $name) ?? ''));
    }
}
