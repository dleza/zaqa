<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\NormalizesZambianPrimaryPhone;
use App\Support\RegistrationOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rule;

class RegisterInstitutionRequest extends FormRequest
{
    use NormalizesZambianPrimaryPhone;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'institution_name' => ['required', 'string', 'max:255'],
            'tpin' => ['required', 'string', 'max:50'],
            'contact_person_name' => ['nullable', 'string', 'max:255'],
            'login_identifier_type' => ['required', 'string', Rule::in(RegistrationOptions::allowedLoginIdentifierTypes())],
            'phone_primary' => array_merge(
                ['nullable', 'required_if:login_identifier_type,phone'],
                $this->zambianPrimaryPhoneFormatRules(),
            ),
            'email' => ['nullable', 'string', 'email:rfc', 'max:255', 'required_if:login_identifier_type,email', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone_primary.digits' => 'Enter a valid Zambian mobile number.',
            'phone_primary.starts_with' => 'Enter a valid Zambian mobile number.',
        ];
    }
}
