<?php

namespace App\Http\Requests\Applicant;

use App\Enums\ApplicantType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateApplicantDetailsRequest extends FormRequest
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
        $user = $this->user();
        $applicantType = $user?->applicant_type;

        $base = [
            'email' => [
                'nullable',
                'required_without:phone_primary',
                'email:rfc,dns',
                'max:255',
                Rule::unique('users', 'email')->ignore($user?->id),
            ],
            'phone_primary' => [
                'nullable',
                'required_without:email',
                'string',
                'max:30',
                Rule::unique('users', 'phone_primary')->ignore($user?->id),
            ],
            'phone_secondary' => ['nullable', 'string', 'max:30'],
        ];

        if ($applicantType === ApplicantType::Institution) {
            return $base + [
                'institution_name' => ['required', 'string', 'max:255'],
                'tpin' => ['nullable', 'string', 'max:50'],
                'contact_person_name' => ['required', 'string', 'max:255'],
            ];
        }

        return $base + [
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'surname' => ['required', 'string', 'max:255'],
            'nrc_number' => ['nullable', 'string', 'max:100'],
            'passport_number' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $user = $this->user();
            if (! $user || ($user->applicant_type?->value ?? null) !== ApplicantType::Individual->value) {
                return;
            }

            $nrc = trim((string) $this->input('nrc_number', ''));
            $passport = trim((string) $this->input('passport_number', ''));

            if ($nrc === '' && $passport === '') {
                $validator->errors()->add('nrc_number', 'Provide NRC or passport number.');
                $validator->errors()->add('passport_number', 'Provide NRC or passport number.');
            }
        });
    }
}
