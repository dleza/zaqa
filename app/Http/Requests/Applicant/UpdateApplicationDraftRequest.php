<?php

namespace App\Http\Requests\Applicant;

use App\Enums\ApplicantType;
use App\Enums\ServiceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class UpdateApplicationDraftRequest extends FormRequest
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
            'service_type' => ['sometimes', 'required', new Enum(ServiceType::class)],
            'qualification_category' => ['sometimes', 'required', 'string', 'max:50'],
            'is_foreign' => ['sometimes', 'required', 'boolean'],
            'submitting_for' => ['sometimes', 'nullable', 'string', 'in:self,other'],
            'subject_first_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'subject_other_names' => ['sometimes', 'nullable', 'string', 'max:255'],
            'subject_last_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'subject_email' => ['sometimes', 'nullable', 'email:rfc,dns', 'max:255'],
            'subject_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'gender' => ['sometimes', 'nullable', 'string', 'in:male,female'],
            'identity_type' => ['sometimes', 'nullable', 'string', 'in:nrc,passport'],
            'identity_number' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->has('submitting_for')) {
                return;
            }

            $submittingFor = (string) $this->input('submitting_for', 'self');
            if ($submittingFor !== 'other') {
                $user = $this->user();
                if (! $user) {
                    return;
                }

                if (($user->applicant_type?->value ?? null) !== ApplicantType::Individual->value) {
                    return;
                }

                $user->loadMissing('applicantProfile');
                $profile = $user->applicantProfile;

                $genderIn = strtolower(trim((string) $this->input('gender', '')));
                $genderEff = $genderIn !== '' ? $genderIn : strtolower(trim((string) ($profile?->gender ?? '')));
                if ($genderEff === '' || ! in_array($genderEff, ['male', 'female'], true)) {
                    $validator->errors()->add('gender', 'Gender is required.');
                }

                $typeIn = strtolower(trim((string) $this->input('identity_type', '')));
                $typeEff = $typeIn !== '' ? $typeIn : strtolower(trim((string) ($profile?->identity_type ?? '')));
                if ($typeEff === '') {
                    $hasNrc = trim((string) ($profile?->nrc_number ?? '')) !== '';
                    $hasPassport = trim((string) ($profile?->passport_number ?? '')) !== '';
                    $typeEff = $hasNrc ? 'nrc' : ($hasPassport ? 'passport' : '');
                }

                if ($typeEff === '' || ! in_array($typeEff, ['nrc', 'passport'], true)) {
                    $validator->errors()->add('identity_type', 'Select NRC or Passport.');
                }

                $numberIn = trim((string) $this->input('identity_number', ''));
                $numberEff = $numberIn !== '' ? $numberIn : (
                    $typeEff === 'passport'
                        ? trim((string) ($profile?->passport_number ?? ''))
                        : trim((string) ($profile?->nrc_number ?? ''))
                );
                if ($numberEff === '') {
                    $validator->errors()->add('identity_number', 'Provide NRC or passport number.');
                }

                return;
            }

            $firstName = trim((string) $this->input('subject_first_name', ''));
            if ($firstName === '') {
                $validator->errors()->add('subject_first_name', 'First name is required when submitting on behalf of someone.');
            }

            $lastName = trim((string) $this->input('subject_last_name', ''));
            if ($lastName === '') {
                $validator->errors()->add('subject_last_name', 'Last name is required when submitting on behalf of someone.');
            }

            $gender = strtolower(trim((string) $this->input('gender', '')));
            if ($gender === '' || ! in_array($gender, ['male', 'female'], true)) {
                $validator->errors()->add('gender', 'Gender is required.');
            }

            $type = strtolower(trim((string) $this->input('identity_type', '')));
            if ($type === '' || ! in_array($type, ['nrc', 'passport'], true)) {
                $validator->errors()->add('identity_type', 'Select NRC or Passport.');
            }

            $number = trim((string) $this->input('identity_number', ''));
            if ($number === '') {
                $validator->errors()->add('identity_number', 'Provide NRC or passport number.');
            }
        });
    }
}
