<?php

namespace App\Http\Requests\Admin\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQualificationTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.qualification_types.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'zqf_level_code' => ['required', 'string', 'max:20'],
            'level_label' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:4000'],
            'billing_category_id' => ['required', 'integer', Rule::exists('billing_categories', 'id')],
            'requires_subject_results' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ];
    }
}

