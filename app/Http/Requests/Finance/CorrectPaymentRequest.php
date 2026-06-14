<?php

namespace App\Http\Requests\Finance;

use App\Enums\PaymentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CorrectPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('finance.payments.correct');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => [
                'required',
                'string',
                Rule::in([
                    PaymentStatus::PendingConfirmation->value,
                    PaymentStatus::Confirmed->value,
                    PaymentStatus::Rejected->value,
                    PaymentStatus::Failed->value,
                    PaymentStatus::Expired->value,
                ]),
            ],
            'note' => ['required', 'string', 'max:2000'],
            'provider_transaction_id' => [
                Rule::requiredIf($this->input('status') === PaymentStatus::Confirmed->value),
                'nullable',
                'string',
                'max:255',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'provider_transaction_id.required' => 'Provider transaction ID is required when confirming a payment.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $status = $this->input('status');
        $note = trim((string) $this->input('note', ''));
        $providerTransactionId = trim((string) $this->input('provider_transaction_id', ''));

        $this->merge([
            'status' => is_string($status) ? trim($status) : $status,
            'note' => $note,
            'provider_transaction_id' => $providerTransactionId !== '' ? $providerTransactionId : null,
        ]);
    }
}
