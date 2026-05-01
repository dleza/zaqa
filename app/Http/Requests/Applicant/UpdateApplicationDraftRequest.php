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
            'subject_full_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'subject_email' => ['sometimes', 'nullable', 'email:rfc,dns', 'max:255'],
            'subject_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'subject_nrc_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'subject_passport_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'profile_nrc_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'profile_passport_number' => ['sometimes', 'nullable', 'string', 'max:100'],
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
                $nrcIn = trim((string) $this->input('profile_nrc_number', ''));
                $passIn = trim((string) $this->input('profile_passport_number', ''));
                $nrcEff = $nrcIn !== '' ? $nrcIn : trim((string) ($user->applicantProfile?->nrc_number ?? ''));
                $passEff = $passIn !== '' ? $passIn : trim((string) ($user->applicantProfile?->passport_number ?? ''));

                if ($nrcEff === '' && $passEff === '') {
                    $validator->errors()->add('profile_nrc_number', 'Provide NRC or passport number.');
                    $validator->errors()->add('profile_passport_number', 'Provide NRC or passport number.');
                }

                return;
            }

            $nrc = trim((string) $this->input('subject_nrc_number', ''));
            $passport = trim((string) $this->input('subject_passport_number', ''));

            if ($nrc === '' && $passport === '') {
                $validator->errors()->add('subject_nrc_number', 'Provide NRC or passport number.');
                $validator->errors()->add('subject_passport_number', 'Provide NRC or passport number.');
            }

            $fullName = trim((string) $this->input('subject_full_name', ''));
            if ($fullName === '') {
                $validator->errors()->add('subject_full_name', 'Full name is required when submitting on behalf of someone.');
            }
        });
    }
}

