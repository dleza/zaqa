<?php

namespace App\Http\Requests\Admin\Settings;

use Illuminate\Foundation\Http\FormRequest;

class StoreBillingCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.billing_categories.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:64', 'alpha_dash', 'unique:billing_categories,code'],
            'description' => ['nullable', 'string', 'max:2000'],
            'local_processing_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'foreign_processing_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge([
                'code' => strtoupper(str_replace([' ', '-'], '_', (string) $this->input('code'))),
            ]);
        }
    }
}
