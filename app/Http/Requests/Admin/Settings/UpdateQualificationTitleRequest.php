<?php

namespace App\Http\Requests\Admin\Settings;

use App\Models\QualificationTitle;
use Illuminate\Foundation\Http\FormRequest;

class UpdateQualificationTitleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.qualification_titles.edit') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'qualification_type_id' => ['nullable', 'integer', 'exists:qualification_types,id'],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'awarding_institution_ids' => ['nullable', 'array'],
            'awarding_institution_ids.*' => ['integer', 'exists:awarding_institutions,id'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            /** @var QualificationTitle|null $title */
            $title = $this->route('qualification_title');
            if (! $title) {
                return;
            }

            $name = trim((string) $this->input('name', ''));
            $normalized = QualificationTitle::normalizeName($name);
            if ($normalized === '') {
                $validator->errors()->add('name', 'Enter a valid qualification title.');

                return;
            }

            $exists = QualificationTitle::query()
                ->where('name_normalized', $normalized)
                ->whereKeyNot($title->id)
                ->exists();

            if ($exists) {
                $validator->errors()->add('name', 'A qualification title with this name already exists.');
            }
        });
    }
}
