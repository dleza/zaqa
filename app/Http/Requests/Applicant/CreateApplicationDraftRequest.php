<?php

namespace App\Http\Requests\Applicant;

use App\Enums\ApplicantType;
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
            'profile_nrc_number' => ['nullable', 'string', 'max:100'],
            'profile_passport_number' => ['nullable', 'string', 'max:100'],
            'identity_document_type' => ['nullable', 'string', 'in:nrc_copy,passport_copy'],
            'identity_file' => ['nullable', 'file', 'max:'.((int) config('documents.max_upload_kb', 10240)), 'mimetypes:'.implode(',', (array) config('documents.allowed_mimetypes', [
                'application/pdf',
                'image/jpeg',
                'image/png',
                'image/webp',
            ]))],
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

            if (! $this->hasFile('identity_file')) {
                $validator->errors()->add('identity_file', 'Upload a clear copy of the holder’s NRC or passport.');
            }
        });
    }
}

