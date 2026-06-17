<?php

namespace App\Http\Requests\Concerns;

use App\Support\Qualifications\CertificateSubjectGrade;
use Illuminate\Validation\Rule;

trait ValidatesCertificateSubjectGrades
{
    /**
     * @return array<int, mixed>
     */
    protected function certificateSubjectGradeRules(bool $requiredWithSubjectResults = false): array
    {
        $rules = ['string', Rule::in(CertificateSubjectGrade::allowed())];

        if ($requiredWithSubjectResults) {
            array_unshift($rules, 'required_with:subject_results');
        } else {
            array_unshift($rules, 'required');
        }

        return $rules;
    }

    protected function prepareSubjectResultGradesForValidation(): void
    {
        $results = $this->input('subject_results');
        if (! is_array($results)) {
            return;
        }

        $merged = collect($results)
            ->map(function ($row) {
                if (! is_array($row)) {
                    return $row;
                }

                $normalized = CertificateSubjectGrade::normalize($row['grade'] ?? null);
                if ($normalized !== null) {
                    $row['grade'] = $normalized;
                }

                return $row;
            })
            ->all();

        $this->merge(['subject_results' => $merged]);
    }

    /**
     * @return array<string, string>
     */
    protected function certificateSubjectGradeMessages(): array
    {
        return [
            'subject_results.*.grade.required' => 'Please select a valid grade.',
            'subject_results.*.grade.required_with' => 'Please select a valid grade.',
            'subject_results.*.grade.in' => 'Please select a valid grade.',
        ];
    }
}
