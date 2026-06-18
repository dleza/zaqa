<?php

namespace App\Http\Requests\Admin\Settings;

use App\Models\AwardingInstitution;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAwardingInstitutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.awarding_institutions.edit') ?? false;
    }

    public function rules(): array
    {
        /** @var AwardingInstitution|null $inst */
        $inst = $this->route('awardingInstitution');

        $maxKb = 5120;

        return [
            'country_id' => ['required', 'integer', Rule::exists('countries', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'consent_form' => ['nullable', 'file', 'max:'.$maxKb, 'mimes:pdf,doc,docx,png,jpg,jpeg'],
            'remove_consent_form' => ['nullable', 'boolean'],
            'accreditation_statement' => ['nullable', 'string', 'max:5000'],
            'unique_scope' => [
                function () use ($inst) {
                    // no-op: keep rule set non-empty for future extensions
                },
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('accreditation_statement'))) {
            $this->merge(['accreditation_statement' => trim($this->input('accreditation_statement')) ?: null]);
        }
    }
}
