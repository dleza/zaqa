<?php

namespace App\Http\Requests\Applicant;

use App\Http\Requests\Concerns\ValidatesUserUploadSize;
use App\Domain\Applications\ApplicationNotificationContact;
use App\Enums\ApplicantType;
use App\Enums\QualificationType;
use App\Enums\ServiceType;
use App\Http\Requests\Applicant\Concerns\ValidatesApplicationNotificationContact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class CreateApplicationDraftRequest extends FormRequest
{
    use ValidatesApplicationNotificationContact;
    use ValidatesUserUploadSize;
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
            'subject_first_name' => ['nullable', 'string', 'max:255'],
            'subject_other_names' => ['nullable', 'string', 'max:255'],
            'subject_last_name' => ['nullable', 'string', 'max:255'],
            'notification_contact_mode' => ['nullable', 'string', 'in:'.ApplicationNotificationContact::MODE_APPLICANT_ACCOUNT.','.ApplicationNotificationContact::MODE_ADDITIONAL_EMAIL],
            'additional_notification_email' => ['nullable', 'email', 'max:255'],
            'additional_notification_name' => ['nullable', 'string', 'max:255'],
            'additional_notification_relationship' => ['nullable', 'string', 'max:100'],
            'gender' => ['nullable', 'string', 'in:male,female'],
            'identity_type' => ['nullable', 'string', 'in:nrc,passport'],
            'identity_number' => ['nullable', 'string', 'max:100'],
            'identity_file' => ['nullable', 'file', 'max:'.$this->userUploadMaxKb(), 'mimetypes:'.implode(',', (array) config('documents.allowed_mimetypes', [
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

                $profileHasIdentityDoc = (bool) ($profile?->identity_document_uploaded_at ?? false);
                if (! $profileHasIdentityDoc && ! $this->hasFile('identity_file')) {
                    $validator->errors()->add('identity_file', 'Upload a clear copy of your NRC or passport.');
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

            if (! $this->hasFile('identity_file')) {
                $validator->errors()->add('identity_file', 'Upload a clear copy of the holder’s NRC or passport.');
            }

            $this->validateApplicationNotificationContact($validator, $submittingFor);
        });
    }

    public function messages(): array
    {
        return $this->userUploadValidationMessages();
    }
}
