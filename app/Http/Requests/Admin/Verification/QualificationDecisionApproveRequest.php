<?php

namespace App\Http\Requests\Admin\Verification;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QualificationDecisionApproveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('verification.decide.approve') ?? false;
    }

    public function rules(): array
    {
        return [
            'findings' => ['required', 'string', 'min:3', 'max:10000'],
            'accreditation_statement' => [
                Rule::requiredIf(fn () => $this->boolean('issue_certificate')),
                'nullable',
                'string',
                'max:2000',
            ],
            'comment' => ['nullable', 'string', 'max:5000'],
            'issue_certificate' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'findings.required' => 'Findings are required.',
            'findings.min' => 'Findings must be at least 3 characters.',
            'findings.max' => 'Findings must not exceed 10,000 characters.',
            'accreditation_statement.required' => 'Accreditation statement is required when issuing a verification certificate.',
            'accreditation_statement.max' => 'Accreditation statement must not exceed 2,000 characters.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'findings' => is_string($this->input('findings')) ? trim($this->input('findings')) : $this->input('findings'),
            'accreditation_statement' => is_string($this->input('accreditation_statement'))
                ? trim($this->input('accreditation_statement'))
                : $this->input('accreditation_statement'),
            'comment' => is_string($this->input('comment')) ? trim($this->input('comment')) : $this->input('comment'),
        ]);
    }
}
