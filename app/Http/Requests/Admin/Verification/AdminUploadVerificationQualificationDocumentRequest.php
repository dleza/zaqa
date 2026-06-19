<?php

namespace App\Http\Requests\Admin\Verification;

use App\Domain\Verification\VerificationQualificationAccess;
use App\Enums\DocumentType;
use App\Http\Requests\Concerns\ValidatesUserUploadSize;
use App\Models\Qualification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class AdminUploadVerificationQualificationDocumentRequest extends FormRequest
{
    use ValidatesUserUploadSize;

    public function authorize(): bool
    {
        $qualification = $this->route('qualification');
        if (! $this->user() || ! $qualification instanceof Qualification) {
            return false;
        }
        if (! $this->user()->can('verification.level1.process') && ! $this->user()->can('verification.level2.review')) {
            return false;
        }

        VerificationQualificationAccess::ensureQualificationAccessible($this->user(), $qualification);

        return true;
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
            'document_type' => [
                'required',
                new Enum(DocumentType::class),
                'in:'.implode(',', [
                    DocumentType::CertificateCopy->value,
                    DocumentType::Transcript->value,
                    DocumentType::ConsentFormSigned->value,
                    DocumentType::ZaqaConsentFormSigned->value,
                    DocumentType::OtherSupportingDocument->value,
                    DocumentType::NrcCopy->value,
                    DocumentType::PassportCopy->value,
                ]),
            ],
            'file' => ['required', 'file', 'max:'.$maxKb, 'mimetypes:'.implode(',', $mimeTypes)],
            'correction_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return $this->userUploadValidationMessages();
    }
}
