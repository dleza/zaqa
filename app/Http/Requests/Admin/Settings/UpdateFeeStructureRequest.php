<?php

namespace App\Http\Requests\Admin\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFeeStructureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.fees.edit') ?? false;
    }

    public function rules(): array
    {
        return [
            'is_active' => ['required', 'boolean'],
            'effective_to' => ['nullable', 'date'],
            'change_reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

