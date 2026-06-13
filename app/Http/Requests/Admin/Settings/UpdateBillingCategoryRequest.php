<?php

namespace App\Http\Requests\Admin\Settings;

use App\Models\BillingCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateBillingCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.billing_categories.edit') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'local_processing_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'foreign_processing_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var BillingCategory|null $category */
            $category = $this->route('billingCategory');
            if (! $category instanceof BillingCategory || ! $category->isSystemCategory()) {
                return;
            }

            if (! (bool) $this->input('is_active')) {
                $validator->errors()->add(
                    'is_active',
                    'The foreign qualifications billing category cannot be deactivated.',
                );
            }
        });
    }
}
