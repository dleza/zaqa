<?php

namespace App\Http\Requests\Applicant;

use App\Http\Requests\Concerns\ValidatesCertificateSubjectGrades;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertSubjectResultsRequest extends FormRequest
{
    use ValidatesCertificateSubjectGrades;

    public function authorize(): bool
    {
        $application = $this->route('application');

        return $this->user() && $application && $this->user()->can('update', $application);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'qualification_id' => ['required', 'integer', 'exists:qualifications,id'],
            'subject_results' => ['required', 'array', 'min:1'],
            'subject_results.*.certificate_subject_id' => [
                'required',
                'integer',
                Rule::exists('certificate_subjects', 'id')->where(fn ($q) => $q->where('is_active', true)),
            ],
            'subject_results.*.grade' => $this->certificateSubjectGradeRules(),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->prepareSubjectResultGradesForValidation();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->certificateSubjectGradeMessages();
    }
}
