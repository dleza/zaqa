<?php

namespace App\Http\Requests\Admin\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAwardingInstitutionAccreditationStatementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.awarding_institutions.edit') ?? false;
    }

    public function rules(): array
    {
        return [
            'accreditation_statement' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('accreditation_statement'))) {
            $this->merge(['accreditation_statement' => trim($this->input('accreditation_statement')) ?: null]);
        }
    }
}
