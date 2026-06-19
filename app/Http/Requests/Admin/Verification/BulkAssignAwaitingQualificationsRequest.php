<?php

namespace App\Http\Requests\Admin\Verification;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class BulkAssignAwaitingQualificationsRequest extends FormRequest
{
    abstract protected function officerRoleNames(): array;

    public function authorize(): bool
    {
        return $this->user()?->can('verification.assign') ?? false;
    }

    public function rules(): array
    {
        return [
            'qualification_ids' => ['required', 'array', 'min:1'],
            'qualification_ids.*' => ['integer', 'distinct', 'exists:qualifications,id'],
            'officer_id' => ['required', 'integer', 'exists:users,id'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $officerId = (int) $this->input('officer_id', 0);
            if ($officerId < 1) {
                return;
            }

            $isEligible = \App\Models\User::query()
                ->whereKey($officerId)
                ->whereNull('applicant_type')
                ->where('is_active', true)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', $this->officerRoleNames()))
                ->exists();

            if (! $isEligible) {
                $validator->errors()->add('officer_id', 'Selected officer is not eligible for this assignment queue.');
            }
        });
    }
}
