<?php

namespace App\Http\Requests\Applicant\Concerns;

use App\Domain\Settings\QualificationTitleQueryService;
use App\Models\QualificationTitle;
use Illuminate\Validation\Validator;

trait ValidatesQualificationTitleSelection
{
    protected function validateQualificationTitleSelection(Validator $validator): void
    {
        $source = trim((string) $this->input('qualification_title_source', ''));
        if ($source === '') {
            $manualTitle = trim((string) $this->input('applicant_entered_qualification_title', ''));
            $titleId = (int) $this->input('qualification_title_id', 0);
            $titleText = trim((string) $this->input('title_of_qualification', ''));

            if ($titleId > 0) {
                $source = 'catalog';
            } elseif ($manualTitle !== '') {
                $source = 'other';
            } elseif ($titleText !== '') {
                // Legacy submissions: free-text title without catalog source/id.
                return;
            } else {
                $source = 'catalog';
            }
        }

        if ($source === 'other') {
            if ($this->filled('qualification_title_id')) {
                $validator->errors()->add('qualification_title_id', 'Remove the catalog title when entering a manual title.');
            }

            return;
        }

        $titleId = (int) $this->input('qualification_title_id', 0);
        if ($titleId < 1) {
            $validator->errors()->add('qualification_title_id', 'Select a qualification title from the list.');

            return;
        }

        $title = QualificationTitle::query()->find($titleId);
        if (! $title || ! $title->is_active) {
            $validator->errors()->add('qualification_title_id', 'Select a valid active qualification title.');

            return;
        }

        $institutionId = $this->input('awarding_institution_id');
        if ((string) $institutionId === 'other') {
            $submittedText = trim((string) $this->input('title_of_qualification', ''));
            if ($submittedText !== '' && $submittedText !== $title->name) {
                $validator->errors()->add('title_of_qualification', 'Qualification title text does not match the selected catalog title.');
            }

            return;
        }

        $institutionIdInt = is_numeric($institutionId) && (string) $institutionId !== 'other'
            ? (int) $institutionId
            : null;

        if (! app(QualificationTitleQueryService::class)->isTitleAllowedForApplicant($title, $institutionIdInt)) {
            $validator->errors()->add('qualification_title_id', 'This qualification title is not available for the selected awarding institution.');

            return;
        }

        $submittedText = trim((string) $this->input('title_of_qualification', ''));
        if ($submittedText !== '' && $submittedText !== $title->name) {
            $validator->errors()->add('title_of_qualification', 'Qualification title text does not match the selected catalog title.');
        }
    }
}
