<?php

namespace App\Support\Qualifications;

use App\Models\Qualification;

/**
 * Normalizes awarding institution fields for qualification edit forms.
 *
 * Legacy rows may only have awarding_institution_name without a catalog id or
 * awarding_institution_name_other — treat those as "other" for combobox UIs.
 */
final class QualificationAwardingInstitutionFormState
{
    /**
     * @return array{
     *   awarding_institution_id: int|string,
     *   awarding_institution_name_other: ?string,
     *   awarding_institution_name: string
     * }
     */
    public static function forForm(Qualification $qualification): array
    {
        $catalogId = $qualification->awarding_institution_id
            ? (int) $qualification->awarding_institution_id
            : null;
        $nameOther = trim((string) ($qualification->awarding_institution_name_other ?? ''));
        $name = trim((string) ($qualification->awarding_institution_name ?? ''));
        $catalogName = trim((string) ($qualification->awardingInstitution?->name ?? ''));

        if ($catalogId) {
            return [
                'awarding_institution_id' => $catalogId,
                'awarding_institution_name_other' => $nameOther !== '' ? $nameOther : null,
                'awarding_institution_name' => $name !== '' ? $name : $catalogName,
            ];
        }

        if ($nameOther !== '') {
            return [
                'awarding_institution_id' => 'other',
                'awarding_institution_name_other' => $nameOther,
                'awarding_institution_name' => $name !== '' ? $name : $nameOther,
            ];
        }

        if ($name !== '') {
            return [
                'awarding_institution_id' => 'other',
                'awarding_institution_name_other' => $name,
                'awarding_institution_name' => $name,
            ];
        }

        return [
            'awarding_institution_id' => '',
            'awarding_institution_name_other' => null,
            'awarding_institution_name' => '',
        ];
    }
}
