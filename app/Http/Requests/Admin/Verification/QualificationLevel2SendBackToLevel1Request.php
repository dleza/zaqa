<?php

namespace App\Http\Requests\Admin\Verification;

use App\Http\Requests\Concerns\ValidatesUserUploadSize;
use Illuminate\Foundation\Http\FormRequest;

class QualificationLevel2SendBackToLevel1Request extends FormRequest
{
    use ValidatesUserUploadSize;

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
                'max:'.$this->userUploadMaxKb(),
                'mimes:pdf,jpg,jpeg,png,webp',
            ],
        ];
    }

    public function messages(): array
    {
        return $this->userUploadValidationMessages();
    }
}
