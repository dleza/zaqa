<?php

namespace App\Http\Requests\Admin\Integrations;

use Illuminate\Foundation\Http\FormRequest;

class EmailInstitutionPullLookupTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('institution_api.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'min:16', 'max:4096'],
        ];
    }
}
