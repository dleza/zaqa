<?php

namespace App\Http\Requests\Admin\Verification;

use Illuminate\Foundation\Http\FormRequest;

class QualificationDecisionRejectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('verification.decide.reject') ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:10000'],
        ];
    }
}

