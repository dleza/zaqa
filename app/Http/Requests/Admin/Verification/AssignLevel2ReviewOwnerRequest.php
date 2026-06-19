<?php

namespace App\Http\Requests\Admin\Verification;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AssignLevel2ReviewOwnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('verification.assign') ?? false;
    }

    public function rules(): array
    {
        return [
            'assigned_to_user_id' => ['required', 'integer', 'exists:users,id'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $assigneeId = (int) $this->input('assigned_to_user_id', 0);
            if ($assigneeId < 1) {
                return;
            }

            $isEligible = \App\Models\User::query()
                ->whereKey($assigneeId)
                ->whereNull('applicant_type')
                ->where('is_active', true)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', [
                    'Verification Officer Level 2',
                    'Super Admin',
                ]))
                ->exists();

            if (! $isEligible) {
                $validator->errors()->add('assigned_to_user_id', 'Selected officer is not eligible for Level 2 assignment.');
            }
        });
    }
}
