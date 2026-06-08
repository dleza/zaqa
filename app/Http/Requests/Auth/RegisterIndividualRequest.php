<?php

namespace App\Http\Requests\Auth;

use App\Support\RegistrationOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rule;

class RegisterIndividualRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'surname' => ['required', 'string', 'max:255'],
            'login_identifier_type' => ['required', 'string', Rule::in(RegistrationOptions::allowedLoginIdentifierTypes())],
            'phone_primary' => ['nullable', 'string', 'max:30', 'required_if:login_identifier_type,phone', 'unique:users,phone_primary'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:255', 'required_if:login_identifier_type,email', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ];
    }
}
