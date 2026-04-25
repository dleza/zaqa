<?php

namespace App\Http\Requests\Applicant;

use App\Enums\QualificationType;
use App\Enums\ServiceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class CreateApplicationDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'service_type' => ['required', new Enum(ServiceType::class)],
            'qualification_category' => ['required', new Enum(QualificationType::class)],
            'is_foreign' => ['required', 'boolean'],
            'submitting_for' => ['nullable', 'string', 'in:self,other'],
            'subject_full_name' => ['nullable', 'string', 'max:255'],
            'subject_email' => ['nullable', 'email:rfc,dns', 'max:255'],
            'subject_phone' => ['nullable', 'string', 'max:30'],
            'subject_nrc_number' => ['nullable', 'string', 'max:100'],
            'subject_passport_number' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $submittingFor = (string) $this->input('submitting_for', 'self');
            if ($submittingFor !== 'other') {
                $user = $this->user();
                if (! $user) {
                    return;
                }

                // When submitting as self, require the authenticated profile to have NRC or Passport.
                // (We enforce this for individual applicants; institutions don't have these fields.)
                if (($user->applicant_type?->value ?? null) !== 'individual') {
                    return;
                }

                $user->loadMissing('applicantProfile');
                $nrc = trim((string) ($user->applicantProfile?->nrc_number ?? ''));
                $passport = trim((string) ($user->applicantProfile?->passport_number ?? ''));

                if ($nrc === '' && $passport === '') {
                    $validator->errors()->add('submitting_for', 'Please update your profile with an NRC or Passport number before submitting as yourself.');
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

