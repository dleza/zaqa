<?php

namespace App\Http\Requests\Admin\Verification;

use App\Http\Requests\Concerns\ValidatesUserUploadSize;
use App\Models\Qualification;
use App\Models\QualificationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class QualificationLevel1CompleteRequest extends FormRequest
{
    use ValidatesUserUploadSize;

    public function authorize(): bool
    {
        return $this->user()?->can('verification.level1.process') ?? false;
    }

    public function rules(): array
    {
        $maxKb = $this->userUploadMaxKb();

        return [
            'qualification_type_id' => ['required', 'integer', 'exists:qualification_types,id'],
            'recommended_for_award' => ['required', 'boolean'],
            'findings' => ['required', 'string', 'min:3', 'max:10000'],
            'accreditation_statement' => [
                Rule::requiredIf(fn () => $this->boolean('recommended_for_award')),
                'nullable',
                'string',
                'max:2000',
            ],
            'evaluation_report' => [
                'nullable',
                'file',
                'max:'.$maxKb,
                'mimes:pdf,jpg,jpeg,png,webp',
            ],
            'attachment' => [
                'nullable',
                'file',
                'max:'.$maxKb,
                'mimes:pdf,jpg,jpeg,png,webp',
            ],
        ];
    }

    public function messages(): array
    {
        return array_merge($this->userUploadValidationMessages(), [
            'accreditation_statement.required' => 'Accreditation statement is required when recommending award.',
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $qualification = $this->route('qualification');
            if (! $qualification instanceof Qualification) {
                return;
            }

            $typeId = (int) $this->input('qualification_type_id', 0);
            $currentTypeId = (int) ($qualification->qualification_type_id ?? 0);

            if ($typeId < 1 || $typeId === $currentTypeId) {
                return;
            }

            $isActive = QualificationType::query()
                ->whereKey($typeId)
                ->where('is_active', true)
                ->exists();

            if (! $isActive) {
                $validator->errors()->add('qualification_type_id', 'Selected qualification type is not available.');
            }
        });
    }
}
