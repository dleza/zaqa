<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class ReviewPaymentProofRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Minimal staff gate: only non-applicant (staff) accounts can review.
        // (The full RBAC module will tighten this later.)
        return (bool) $this->user() && ($this->user()->applicant_type?->value ?? null) === null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'comment' => ['nullable', 'string', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

