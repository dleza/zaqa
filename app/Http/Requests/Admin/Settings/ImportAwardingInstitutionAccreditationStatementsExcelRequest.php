<?php

namespace App\Http\Requests\Admin\Settings;

use Illuminate\Foundation\Http\FormRequest;

class ImportAwardingInstitutionAccreditationStatementsExcelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.awarding_institutions.edit') ?? false;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:12288', 'mimes:xlsx,xls,csv'],
            'overwrite_existing' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('overwrite_existing')) {
            $this->merge([
                'overwrite_existing' => filter_var($this->input('overwrite_existing'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }
    }
}
