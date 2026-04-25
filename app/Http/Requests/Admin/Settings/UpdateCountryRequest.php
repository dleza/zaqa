<?php

namespace App\Http\Requests\Admin\Settings;

use App\Models\Country;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCountryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.countries.edit') ?? false;
    }

    public function rules(): array
    {
        /** @var Country|null $country */
        $country = $this->route('country');

        return [
            'name' => ['required', 'string', 'max:255'],
            'iso_code' => [
                'required',
                'string',
                'size:3',
                'alpha',
                Rule::unique('countries', 'iso_code')->ignore($country?->id),
            ],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ];
    }
}

