<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerificationReferenceLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'application_reference' => ['nullable', 'string', 'max:64'],
            'qualification_reference' => ['nullable', 'string', 'max:64'],
        ];
    }
}
