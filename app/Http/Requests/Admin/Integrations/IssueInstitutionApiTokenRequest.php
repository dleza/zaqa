<?php

namespace App\Http\Requests\Admin\Integrations;

use Illuminate\Foundation\Http\FormRequest;

class IssueInstitutionApiTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('institution_api.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'token_name' => ['required', 'string', 'max:255'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', 'max:100'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ];
    }
}

