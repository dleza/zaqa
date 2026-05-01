<?php

namespace App\Http\Requests\Applicant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UploadForeignConsentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $application = $this->route('application');

        return $this->user() && $application && $this->user()->can('update', $application);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $maxKb = (int) config('documents.max_upload_kb', 10240);
        $mimeTypes = (array) config('documents.allowed_mimetypes', [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
        ]);

        return [
            'qualification_id' => ['required', 'integer', 'exists:qualifications,id'],
            // Awarding institution consent file
            'file' => ['required', 'file', 'max:'.$maxKb, 'mimetypes:'.implode(',', $mimeTypes)],
            // ZAQA consent file (required for foreign applications)
            'zaqa_file' => ['nullable', 'file', 'max:'.$maxKb, 'mimetypes:'.implode(',', $mimeTypes)],
            // Canonical term
            'source_awarding_institution_name' => ['nullable', 'string', 'max:255'],
            // Back-compat alias for older clients
            'source_awarding_body_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $application = $this->route('application');
            $qualificationId = (int) ($this->input('qualification_id') ?? 0);
            $qualification = $qualificationId > 0
                ? \App\Models\Qualification::query()->whereKey($qualificationId)->first()
                : null;

            if (! $qualification || ! $application || (int) $qualification->application_id !== (int) $application->id) {
                $validator->errors()->add('qualification_id', 'Selected qualification is invalid for this application.');
                return;
            }

            $isForeign = (bool) ($qualification->is_foreign_qualification ?? false);

            if (! $this->file('zaqa_file')) {
                $validator->errors()->add('zaqa_file', 'ZAQA consent form is required for foreign applications.');
            }
        });
    }
}

