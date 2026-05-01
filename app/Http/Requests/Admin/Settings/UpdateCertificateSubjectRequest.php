<?php

namespace App\Http\Requests\Admin\Settings;

use App\Models\CertificateSubject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCertificateSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.certificate_subjects.edit') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var CertificateSubject $subject */
        $subject = $this->route('certificate_subject');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('certificate_subjects', 'name')->ignore($subject->id),
            ],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
