<?php

namespace App\Http\Requests\Applicant;

use App\Http\Requests\Applicant\Concerns\ValidatesNamesAsOnQualificationDocument;
use App\Http\Requests\Applicant\Concerns\ValidatesQualificationTitleSelection;
use App\Http\Requests\Concerns\ValidatesCertificateSubjectGrades;
use App\Support\Applications\ApplicationSubmissionMode;
use App\Support\Qualifications\CertificateSubjectGrade;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpsertInstitutionalQualificationRequest extends FormRequest
{
    use ValidatesNamesAsOnQualificationDocument;
    use ValidatesQualificationTitleSelection;
    use ValidatesCertificateSubjectGrades;

    public function authorize(): bool
    {
        $application = $this->route('application');

        return $this->user()
            && $application
            && $this->user()->can('update', $application)
            && ApplicationSubmissionMode::isInstitutionalMultiple($application);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'qualification_id' => ['nullable', 'integer', 'exists:qualifications,id'],
            'create_new' => ['nullable', 'boolean'],
            'holder_first_name' => ['required', 'string', 'max:120'],
            'holder_middle_name' => ['nullable', 'string', 'max:120'],
            'holder_surname' => ['required', 'string', 'max:120'],
            'holder_identity_type' => ['nullable', 'string', Rule::in(['nrc', 'passport'])],
            'holder_date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'holder_gender' => ['nullable', 'string', Rule::in(['male', 'female', 'other'])],
            'holder_phone' => ['nullable', 'string', 'max:50'],
            'holder_email' => ['nullable', 'email', 'max:255'],
            'nrc_passport_number' => ['required', 'string', 'max:100'],
            'awarding_institution_id' => ['nullable'],
            'awarding_institution_name_other' => ['nullable', 'string', 'max:255'],
            'awarding_institution_name' => ['required', 'string', 'max:255'],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'country_name_other' => ['nullable', 'string', 'max:255'],
            'certificate_number' => ['nullable', 'string', 'max:100'],
            'student_number' => ['nullable', 'string', 'max:100'],
            'examination_number' => ['nullable', 'string', 'max:100'],
            'title_of_qualification' => ['required', 'string', 'max:255'],
            ...$this->namesAsOnQualificationDocumentRules(),
            'qualification_title_id' => ['nullable', 'integer', 'exists:qualification_titles,id'],
            'qualification_title_source' => ['nullable', 'string', Rule::in(['catalog', 'other'])],
            'applicant_entered_qualification_title' => ['nullable', 'string', 'max:255'],
            'award_date' => ['required', 'date', 'before_or_equal:today'],
            'qualification_type_id' => [
                'required',
                'integer',
                Rule::exists('qualification_types', 'id')->where(fn ($q) => $q->where('is_active', true)),
            ],
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
        if ($this->has('names_as_on_qualification_document')) {
            $this->merge([
                'names_as_on_qualification_document' => trim((string) $this->input('names_as_on_qualification_document', '')),
            ]);
        }

        foreach (['awarding_institution_name', 'awarding_institution_name_other', 'holder_first_name', 'holder_middle_name', 'holder_surname', 'nrc_passport_number'] as $field) {
            if ($this->has($field)) {
                $this->merge([$field => trim((string) $this->input($field, ''))]);
            }
        }

        $this->prepareSubjectResultGradesForValidation();
        $this->dropEmptySubjectResultRows();
    }

    private function dropEmptySubjectResultRows(): void
    {
        $subjectResults = $this->input('subject_results');
        if (! is_array($subjectResults)) {
            return;
        }

        $filtered = array_values(array_filter($subjectResults, function ($row) {
            if (! is_array($row)) {
                return false;
            }

            $subjectId = (int) ($row['certificate_subject_id'] ?? 0);
            $grade = trim((string) ($row['grade'] ?? ''));

            return $subjectId > 0 || $grade !== '';
        }));

        $this->merge(['subject_results' => $filtered]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(
            $this->namesAsOnQualificationDocumentMessages(),
            $this->certificateSubjectGradeMessages(),
            [
                'holder_first_name.required' => 'First name is required for the qualification holder.',
                'holder_surname.required' => 'Surname is required for the qualification holder.',
                'nrc_passport_number.required' => 'NRC or passport number is required for the qualification holder.',
            ],
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
                } else {
                    $this->validateCompleteSubjectResultRows($validator, $subjectResults);

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

            $this->validateQualificationTitleSelection($validator);
        });
    }

    /**
     * @param  array<int, mixed>  $subjectResults
     */
    private function validateCompleteSubjectResultRows(Validator $validator, array $subjectResults): void
    {
        $completeRows = 0;

        foreach ($subjectResults as $index => $row) {
            if (! is_array($row)) {
                $validator->errors()->add("subject_results.$index", 'Each subject row must be complete.');

                continue;
            }

            $subjectId = (int) ($row['certificate_subject_id'] ?? 0);
            $grade = trim((string) ($row['grade'] ?? ''));
            $hasSubject = $subjectId > 0;
            $hasGrade = CertificateSubjectGrade::isAllowed($grade);

            if (! $hasSubject) {
                $validator->errors()->add("subject_results.$index.certificate_subject_id", 'Select a subject for each row.');
            }

            if (! $hasGrade) {
                $validator->errors()->add("subject_results.$index.grade", 'Please select a valid grade for each subject.');
            }

            if ($hasSubject && $hasGrade) {
                $completeRows++;
            }
        }

        if ($completeRows < 1) {
            $validator->errors()->add('subject_results', 'Add at least one subject with a grade.');
        }
    }
}
