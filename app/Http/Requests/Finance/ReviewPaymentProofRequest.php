<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class ReviewPaymentProofRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
        }

        // Finance actions are RBAC-controlled; controllers also enforce specific action permissions.
        return $user->can('finance.payment_proofs.review')
            || $user->can('finance.payment_proofs.approve')
            || $user->can('finance.payment_proofs.reject');
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
