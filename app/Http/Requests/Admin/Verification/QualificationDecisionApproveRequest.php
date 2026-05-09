<?php

namespace App\Http\Requests\Admin\Verification;

use Illuminate\Foundation\Http\FormRequest;

class QualificationDecisionApproveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('verification.decide.approve') ?? false;
    }

    public function rules(): array
    {
        return [
            'comment' => ['nullable', 'string', 'max:5000'],
            'issue_certificate' => ['nullable', 'boolean'],
        ];
    }
}

