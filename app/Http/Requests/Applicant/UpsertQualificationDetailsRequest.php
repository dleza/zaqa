<?php

namespace App\Http\Requests\Applicant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpsertQualificationDetailsRequest extends FormRequest
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
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'awarding_institution_id' => ['nullable'],
            'awarding_institution_name_other' => ['nullable', 'string', 'max:255'],
            'certificate_number' => ['nullable', 'string', 'max:100'],
            'student_number' => ['nullable', 'string', 'max:100'],
            'examination_number' => ['nullable', 'string', 'max:100'],
            'title_of_qualification' => ['required', 'string', 'max:255'],
            'award_date' => ['required', 'date', 'before_or_equal:today'],
            'qualification_type_id' => ['required', 'integer', 'exists:qualification_types,id'],
            'transcript_reason' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $certificateNumber = trim((string) $this->input('certificate_number', ''));
            $studentNumber = trim((string) $this->input('student_number', ''));
            $examinationNumber = trim((string) $this->input('examination_number', ''));

            if ($certificateNumber === '' && $studentNumber === '' && $examinationNumber === '') {
                $validator->errors()->add('certificate_number', 'Provide an identifier value (certificate number, student number, or examination number).');
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

                $countryIdValue = $this->input('country_id');
                if ($countryIdValue) {
                    $exists = \App\Models\AwardingInstitution::query()
                        ->whereKey((int) $awardingInstitutionId)
                        ->where('country_id', (int) $countryIdValue)
                        ->exists();

                    if (! $exists) {
                        $validator->errors()->add('awarding_institution_id', 'Selected institution does not match the selected country.');
                    }
                }
            }
        });
    }
}

