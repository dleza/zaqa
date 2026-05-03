<?php

namespace App\Http\Requests\Admin\Settings;

use Illuminate\Foundation\Http\FormRequest;

class ImportCountriesExcelRequest extends FormRequest
{
    public function authorize(): bool
    {
        $u = $this->user();

        return $u && ($u->can('settings.countries.create') || $u->can('settings.countries.edit'));
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:12288', 'mimes:xlsx,xls,csv'],
        ];
    }
}
