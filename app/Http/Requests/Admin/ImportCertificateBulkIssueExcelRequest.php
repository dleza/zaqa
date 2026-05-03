<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ImportCertificateBulkIssueExcelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('verification.certificate.issue') ?? false;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:12288', 'mimes:xlsx,xls,csv'],
        ];
    }
}
