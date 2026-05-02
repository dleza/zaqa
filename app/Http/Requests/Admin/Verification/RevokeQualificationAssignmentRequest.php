<?php

namespace App\Http\Requests\Admin\Verification;

use Illuminate\Foundation\Http\FormRequest;

class RevokeQualificationAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('verification.assign') ?? false;
    }

    public function rules(): array
    {
        return [
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
