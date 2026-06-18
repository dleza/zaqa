<?php

namespace App\Http\Requests\Admin\Verification;

use App\Domain\Applications\QualificationCaptureService;
use App\Domain\Verification\VerificationQualificationAccess;
use App\Http\Requests\Concerns\ValidatesCertificateSubjectGrades;
use App\Models\AwardingInstitution;
use App\Models\Qualification;
use App\Models\QualificationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AdminUpdateVerificationQualificationRequest extends FormRequest
{
    use ValidatesCertificateSubjectGrades;

    public function authorize(): bool
    {
        $qualification = $this->route('qualification');
        if (! $this->user() || ! $qualification instanceof Qualification) {
            return false;
        }
        if (! $this->user()->can('verification.level1.process') && ! $this->user()->can('verification.level2.review')) {
            return false;
        }
        VerificationQualificationAccess::ensureQualificationAccessible($this->user(), $qualification);

        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'qualification_holder_name' => ['required', 'string', 'max:255'],
            'names_as_on_qualification_document' => ['required', 'string', 'max:255'],
            'nrc_passport_number' => ['required', 'string', 'max:100'],
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'country_name_other' => ['nullable', 'string', 'max:255'],
            'awarding_institution_id' => ['nullable'],
            'awarding_institution_name_other' => ['nullable', 'string', 'max:255'],
            'awarding_institution_name' => ['required', 'string', 'max:255'],
            'certificate_number' => ['nullable', 'string', 'max:100'],
            'student_number' => ['nullable', 'string', 'max:100'],
            'examination_number' => ['nullable', 'string', 'max:100'],
            'title_of_qualification' => ['required', 'string', 'max:255'],
            'award_date' => ['required', 'date', 'before_or_equal:today'],
            'qualification_type_id' => ['required', 'integer', 'exists:qualification_types,id'],
            'correction_note' => ['nullable', 'string', 'max:2000'],
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
        return $this->certificateSubjectGradeMessages();
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
                    $exists = AwardingInstitution::query()
                        ->whereKey((int) $awardingInstitutionId)
                        ->where('country_id', (int) $countryIdValue)
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
                $requiresSubjects = (bool) QualificationType::query()
                    ->whereKey($qualificationTypeId)
                    ->value('requires_subject_results');
            }

            if ($requiresSubjects) {
                if (! is_array($subjectResults) || count($subjectResults) < 1) {
                    $validator->errors()->add('subject_results', 'Subject results are required for this qualification type.');
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

            $qualification = $this->route('qualification');
            if (! $qualification instanceof Qualification || $validator->errors()->isNotEmpty()) {
                return;
            }

            $wouldChange = app(QualificationCaptureService::class)
                ->adminVerificationCorrectionWouldChange($qualification, $this->all());

            if (! $wouldChange) {
                return;
            }

            $note = trim((string) $this->input('correction_note', ''));
            if (mb_strlen($note) < 3) {
                $validator->errors()->add('correction_note', 'Correction note is required when saving changes.');
            }
        });
    }
}
