<?php

namespace App\Http\Requests\Admin\Verification;

use Illuminate\Foundation\Http\FormRequest;

class QualificationLevel2SendBackToLevel1Request extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('verification.level2.review') ?? false;
    }

    public function rules(): array
    {
        return [
            'comment' => ['required', 'string', 'min:3', 'max:5000'],
            'attachment' => [
                'nullable',
                'file',
                'max:10240',
                'mimes:pdf,doc,docx,jpg,jpeg,png,gif,webp',
            ],
        ];
    }
}
