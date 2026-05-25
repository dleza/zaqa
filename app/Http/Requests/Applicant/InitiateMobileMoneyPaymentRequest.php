<?php

namespace App\Http\Requests\Applicant;

use Illuminate\Foundation\Http\FormRequest;

class InitiateMobileMoneyPaymentRequest extends FormRequest
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
            'mobile_number' => ['required', 'string', 'max:30'],
        ];
    }
}
