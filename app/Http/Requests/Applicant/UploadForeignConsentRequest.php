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
            $isForeign = (bool) ($application?->is_foreign ?? false);
            if (! $isForeign) {
                return;
            }

            if (! $this->file('zaqa_file')) {
                $validator->errors()->add('zaqa_file', 'ZAQA consent form is required for foreign applications.');
            }
        });
    }
}

