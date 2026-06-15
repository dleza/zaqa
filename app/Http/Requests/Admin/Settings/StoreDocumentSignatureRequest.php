<?php

namespace App\Http\Requests\Admin\Settings;

use App\Enums\DocumentSignatureType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentSignatureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('settings.document_signatures.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(DocumentSignatureType::class)],
            'display_name' => ['nullable', 'string', 'max:120'],
            'file' => ['required', 'file', 'mimes:png', 'max:2048'],
        ];
    }
}
