<?php

namespace App\Http\Requests\Applicant;

use Illuminate\Foundation\Http\FormRequest;

class UploadApplicantIdentityDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
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
            'file' => ['required', 'file', 'max:'.$maxKb, 'mimetypes:'.implode(',', $mimeTypes)],
        ];
    }
}
