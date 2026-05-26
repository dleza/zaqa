<?php

namespace App\Http\Requests\Admin\Integrations;

use Illuminate\Foundation\Http\FormRequest;

class EmailInstitutionApiTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('institution_api.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'min:10', 'max:4096'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', 'max:100'],
        ];
    }
}

