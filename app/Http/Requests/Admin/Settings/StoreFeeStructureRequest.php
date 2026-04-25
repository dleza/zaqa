<?php

namespace App\Http\Requests\Admin\Settings;

use App\Support\Money\MoneyNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFeeStructureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.fees.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'billing_category_id' => ['required', 'integer', Rule::exists('billing_categories', 'id')],
            'local_fee' => ['nullable', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'foreign_fee' => ['nullable', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'currency' => ['required', 'string', 'size:3'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'is_active' => ['required', 'boolean'],
            'change_reason' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'local_fee' => is_string($this->input('local_fee')) ? str_replace(',', '', trim((string) $this->input('local_fee'))) : $this->input('local_fee'),
            'foreign_fee' => is_string($this->input('foreign_fee')) ? str_replace(',', '', trim((string) $this->input('foreign_fee'))) : $this->input('foreign_fee'),
        ]);
    }

    public function validated($key = null, $default = null)
    {
        /** @var array $data */
        $data = parent::validated($key, $default);

        // Normalize UI-entered ZMW amounts to minor units for storage.
        $data['local_fee_cents'] = MoneyNormalizer::toMinorUnits($this->input('local_fee'));
        $data['foreign_fee_cents'] = MoneyNormalizer::toMinorUnits($this->input('foreign_fee'));

        return $data;
    }
}

