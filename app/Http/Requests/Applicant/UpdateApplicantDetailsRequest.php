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
            'gender' => ['required', 'string', 'in:male,female'],
            'identity_type' => ['required', 'string', 'in:nrc,passport'],
            'identity_number' => ['required', 'string', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        // no-op (rules handle all validation)
    }
}
