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
        ];
    }
}
