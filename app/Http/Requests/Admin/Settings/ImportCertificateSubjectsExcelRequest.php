<?php

namespace App\Http\Requests\Admin\Settings;

use Illuminate\Foundation\Http\FormRequest;

class ImportCertificateSubjectsExcelRequest extends FormRequest
{
    public function authorize(): bool
    {
        $u = $this->user();

        return $u && ($u->can('settings.certificate_subjects.create') || $u->can('settings.certificate_subjects.edit'));
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:12288', 'mimes:xlsx,xls,csv'],
        ];
    }
}
