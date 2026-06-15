<?php

namespace App\Http\Requests\Admin\Settings;

use Illuminate\Foundation\Http\FormRequest;

class ImportQualificationTitlesExcelRequest extends FormRequest
{
    public function authorize(): bool
    {
        $u = $this->user();

        return $u && ($u->can('settings.qualification_titles.create') || $u->can('settings.qualification_titles.edit'));
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:12288', 'mimes:xlsx,xls,csv'],
        ];
    }
}
