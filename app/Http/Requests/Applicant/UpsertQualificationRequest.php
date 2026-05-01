<?php

namespace App\Http\Requests\Applicant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpsertQualificationRequest extends FormRequest
{
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
            'qualification_id' => ['nullable', 'integer', 'exists:qualifications,id'],
            'create_new' => ['nullable', 'boolean'],
            'awarding_institution_name' => ['required', 'string', 'max:255'],
            'qualification_holder_name' => ['required', 'string', 'max:255'],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'country_name_other' => ['nullable', 'string', 'max:255'],
            'nrc_passport_number' => ['required', 'string', 'max:100'],
            'certificate_number' => ['nullable', 'string', 'max:100'],
            'student_number' => ['nullable', 'string', 'max:100'],
            'examination_number' => ['nullable', 'string', 'max:100'],
            'title_of_qualification' => ['required', 'string', 'max:255'],
            'award_date' => ['required', 'date', 'before_or_equal:today'],
            'qualification_type_id' => ['required', 'integer', 'exists:qualification_types,id'],
            'transcript_reason' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'subject_results' => ['nullable', 'array'],
            'subject_results.*.subject_name' => ['required_with:subject_results', 'string', 'max:255'],
            'subject_results.*.grade' => ['required_with:subject_results', 'string', 'max:50'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $certificateNumber = trim((string) $this->input('certificate_number', ''));
            $studentNumber = trim((string) $this->input('student_number', ''));
            $examinationNumber = trim((string) $this->input('examination_number', ''));

            if ($certificateNumber === '' && $studentNumber === '' && $examinationNumber === '') {
                $validator->errors()->add('certificate_number', 'Provide at least one of certificate number, student number, or examination number.');
            }

            $countryId = $this->input('country_id');
            $countryOther = trim((string) $this->input('country_name_other', ''));
            if (! $countryId && $countryOther === '') {
                $validator->errors()->add('country_id', 'Country of award is required.');
            }

            $qualificationTypeId = (int) $this->input('qualification_type_id', 0);
            $subjectResults = $this->input('subject_results');

            $requiresSubjects = false;
            if ($qualificationTypeId > 0) {
                $requiresSubjects = (bool) \App\Models\QualificationType::query()
                    ->whereKey($qualificationTypeId)
                    ->value('requires_subject_results');
            }

            if ($requiresSubjects) {
                if (! is_array($subjectResults) || count($subjectResults) < 1) {
                    $validator->errors()->add('subject_results', 'Subject results are required for school certificates.');
                }
            }
        });
    }
}
