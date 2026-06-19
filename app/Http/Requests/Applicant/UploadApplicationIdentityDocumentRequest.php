<?php

namespace App\Http\Requests\Applicant;

use App\Http\Requests\Concerns\ValidatesUserUploadSize;
use Illuminate\Foundation\Http\FormRequest;

class UploadApplicationIdentityDocumentRequest extends FormRequest
{
    use ValidatesUserUploadSize;

    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $maxKb = $this->userUploadMaxKb();
        $mimeTypes = (array) config('documents.allowed_mimetypes', [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
        ]);

        return [
            'identity_type' => ['required', 'string', 'in:nrc,passport'],
            'file' => ['required', 'file', 'max:'.$maxKb, 'mimetypes:'.implode(',', $mimeTypes)],
        ];
    }

    public function messages(): array
    {
        return $this->userUploadValidationMessages();
    }
}
