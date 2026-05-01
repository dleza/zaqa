<?php

namespace App\Http\Requests\Applicant;

use App\Enums\DocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UploadApplicationDocumentRequest extends FormRequest
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
            'document_type' => ['required', new Enum(DocumentType::class)],
            'file' => ['required', 'file', 'max:'.$maxKb, 'mimetypes:'.implode(',', $mimeTypes)],
            // For qualification-scoped document types (e.g., certificate/transcript/consent),
            // the UI should send a qualification_id. Application-scoped types may omit it.
            'qualification_id' => ['nullable', 'integer', 'exists:qualifications,id'],
        ];
    }
}

