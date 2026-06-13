<?php

namespace App\Http\Requests\Admin\Settings;

use Illuminate\Foundation\Http\FormRequest;

class AddSmsBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('sms.balance.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
