<?php

namespace App\Http\Requests\Admin\Integrations;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInstitutionApiClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('institution_api.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'awarding_institution_id' => ['required', 'integer', Rule::exists('awarding_institutions', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['required', 'boolean'],
            'scopes' => ['nullable', 'array'],
            'scopes.*' => ['string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
