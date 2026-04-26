<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterInstitutionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'institution_name' => ['required', 'string', 'max:255'],
            'tpin' => ['required', 'string', 'max:50'],
            'contact_person_name' => ['nullable', 'string', 'max:255'],
            'phone_primary' => ['required', 'string', 'max:30', 'unique:users,phone_primary'],
            'phone_secondary' => ['nullable', 'string', 'max:30'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ];
    }
}
