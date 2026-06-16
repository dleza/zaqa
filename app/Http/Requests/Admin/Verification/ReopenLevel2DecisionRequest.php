<?php

namespace App\Http\Requests\Admin\Verification;

use App\Domain\Verification\QualificationDecisionReopenService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReopenLevel2DecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('verification.decision.reopen') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'intended_action' => ['required', 'string', Rule::in(QualificationDecisionReopenService::intendedActions())],
            'confirm' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirm.accepted' => 'Confirm that you understand this will reopen the Level 2 decision.',
        ];
    }
}
