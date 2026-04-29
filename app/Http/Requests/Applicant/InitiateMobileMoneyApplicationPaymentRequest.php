<?php

namespace App\Http\Requests\Applicant;

use Illuminate\Foundation\Http\FormRequest;

class InitiateMobileMoneyApplicationPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $application = $this->route('application');

        return $this->user() && $application && $this->user()->can('update', $application);
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

