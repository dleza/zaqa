<?php

namespace App\Http\Requests\Admin\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAwardingInstitutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.awarding_institutions.create') ?? false;
    }

    public function rules(): array
    {
        $maxKb = 5120;

        return [
            'country_id' => ['required', 'integer', Rule::exists('countries', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'consent_form' => ['nullable', 'file', 'max:'.$maxKb, 'mimes:pdf,doc,docx,png,jpg,jpeg'],
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
