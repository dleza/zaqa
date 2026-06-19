<?php

namespace App\Http\Requests\Applicant;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ConfirmCyberSourceCardPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $payment = $this->route('payment');

        return $this->user() && $payment && $this->user()->can('view', $payment->application);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'transient_token_jwt' => ['required', 'string', 'max:20000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $payment = $this->route('payment');
            if (! $payment) {
                return;
            }

            $payment->loadMissing('invoice');

            if ($payment->method !== PaymentMethod::Card || $payment->provider !== 'cybersource') {
                $validator->errors()->add('payment', 'This payment is not a CyberSource card payment.');
            }

            if ($payment->status === PaymentStatus::Confirmed) {
                $validator->errors()->add('payment', 'Payment is already confirmed.');
            }

            if ($payment->status === PaymentStatus::PendingConfirmation) {
                $validator->errors()->add('payment', 'Payment confirmation is already pending.');
            }

            if ($payment->invoice && ($payment->invoice->status === InvoiceStatus::Paid || $payment->invoice->paid_at)) {
                $validator->errors()->add('payment', 'Payment is already confirmed for this invoice.');
            }
        });
    }
}
