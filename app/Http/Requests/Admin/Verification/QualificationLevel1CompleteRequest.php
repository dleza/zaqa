<?php

namespace App\Http\Requests\Admin\Verification;

use Illuminate\Foundation\Http\FormRequest;

class QualificationLevel1CompleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('verification.level1.process') ?? false;
    }

    public function rules(): array
    {
        return [
            'findings' => ['required', 'string', 'min:3', 'max:10000'],
            'attachment' => [
                'nullable',
                'file',
                'max:10240',
                'mimes:pdf,doc,docx,jpg,jpeg,png,gif,webp',
            ],
        ];
    }
}
