<?php

namespace App\Http\Requests\Applicant;

use App\Http\Requests\Applicant\Concerns\ValidatesNamesAsOnQualificationDocument;
use App\Http\Requests\Applicant\Concerns\ValidatesQualificationTitleSelection;
use App\Http\Requests\Concerns\ValidatesCertificateSubjectGrades;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpsertQualificationRequest extends FormRequest
{
    use ValidatesNamesAsOnQualificationDocument;
    use ValidatesQualificationTitleSelection;
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
            'qualification_id' => ['nullable', 'integer', 'exists:qualifications,id'],
            'create_new' => ['nullable', 'boolean'],
            'awarding_institution_id' => ['nullable'],
            'awarding_institution_name_other' => ['nullable', 'string', 'max:255'],
            'awarding_institution_name' => ['required', 'string', 'max:255'],
            // Ignored for persistence — holder identity comes from the application verification subject.
            'qualification_holder_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'country_name_other' => ['nullable', 'string', 'max:255'],
            // Ignored for persistence — holder identity comes from the application verification subject.
            'nrc_passport_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'certificate_number' => ['nullable', 'string', 'max:100'],
            'student_number' => ['nullable', 'string', 'max:100'],
            'examination_number' => ['nullable', 'string', 'max:100'],
            'title_of_qualification' => ['required', 'string', 'max:255'],
            ...$this->namesAsOnQualificationDocumentRules(),
            'qualification_title_id' => ['nullable', 'integer', 'exists:qualification_titles,id'],
            'qualification_title_source' => ['nullable', 'string', Rule::in(['catalog', 'other'])],
            'applicant_entered_qualification_title' => ['nullable', 'string', 'max:255'],
            'award_date' => ['required', 'date', 'before_or_equal:today'],
            'qualification_type_id' => ['required', 'integer', 'exists:qualification_types,id'],
            'transcript_reason' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'subject_results' => ['nullable', 'array'],
            'subject_results.*.certificate_subject_id' => [
                'required_with:subject_results',
                'integer',
                Rule::exists('certificate_subjects', 'id')->where(fn ($q) => $q->where('is_active', true)),
            ],
            'subject_results.*.grade' => $this->certificateSubjectGradeRules(requiredWithSubjectResults: true),
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
        return array_merge(
            $this->namesAsOnQualificationDocumentMessages(),
            $this->certificateSubjectGradeMessages(),
        );
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

            $awardingInstitutionId = $this->input('awarding_institution_id');
            $awardingInstitutionOther = trim((string) $this->input('awarding_institution_name_other', ''));

            if (! $awardingInstitutionId && $awardingInstitutionOther === '') {
                $validator->errors()->add('awarding_institution_id', 'Awarding institution is required (select one or choose “Other”).');
            }

            if ((string) $awardingInstitutionId === 'other' && $awardingInstitutionOther === '') {
                $validator->errors()->add('awarding_institution_name_other', 'Please type the awarding institution name.');
            }

            if ($awardingInstitutionId && (string) $awardingInstitutionId !== 'other' && $awardingInstitutionOther !== '') {
                $validator->errors()->add('awarding_institution_name_other', 'Remove the manual institution name when selecting from the list.');
            }

            if ($awardingInstitutionId && (string) $awardingInstitutionId !== 'other') {
                if (! is_numeric($awardingInstitutionId) || (int) $awardingInstitutionId < 1) {
                    $validator->errors()->add('awarding_institution_id', 'Select a valid awarding institution.');
                }

                if ($countryId) {
                    $exists = \App\Models\AwardingInstitution::query()
                        ->whereKey((int) $awardingInstitutionId)
                        ->where('country_id', (int) $countryId)
                        ->exists();

                    if (! $exists) {
                        $validator->errors()->add('awarding_institution_id', 'Selected institution does not match the selected country.');
                    }
                }
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
                } elseif (is_array($subjectResults)) {
                    $ids = collect($subjectResults)
                        ->pluck('certificate_subject_id')
                        ->filter(fn ($id) => (int) $id > 0)
                        ->map(fn ($id) => (int) $id)
                        ->all();
                    if (count($ids) !== count(array_unique($ids))) {
                        $validator->errors()->add('subject_results', 'Each subject may only be selected once.');
                    }
                }
            }

            $source = trim((string) $this->input('qualification_title_source', ''));
            $manualTitle = trim((string) $this->input('applicant_entered_qualification_title', ''));
            if ($source === 'other' && $manualTitle === '') {
                $validator->errors()->add('applicant_entered_qualification_title', 'Please type the qualification title.');
            }
            if ($manualTitle !== '' && $source !== '' && $source !== 'other') {
                $validator->errors()->add('applicant_entered_qualification_title', 'Remove the typed title when selecting from the list.');
            }

            $this->validateQualificationTitleSelection($validator);
        });
    }
}
