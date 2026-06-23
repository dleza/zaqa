<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        if ($this->usesUnifiedReferenceInput()) {
            return [
                'reference_type' => [
                    'required',
                    Rule::in(['application_reference', 'qualification_reference', 'certificate_reference']),
                ],
                'reference' => ['required', 'string', 'min:3', 'max:64'],
            ];
        }

        return [
            'application_reference' => ['nullable', 'string', 'max:64'],
            'qualification_reference' => ['nullable', 'string', 'max:64'],
            'certificate_reference' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function usesUnifiedReferenceInput(): bool
    {
        return $this->has('reference_type') || $this->has('reference');
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    public function lookupInputs(): array
    {
        if ($this->usesUnifiedReferenceInput()) {
            $reference = (string) $this->input('reference', '');

            return match ((string) $this->input('reference_type', '')) {
                'application_reference' => [$reference, '', ''],
                'qualification_reference' => ['', $reference, ''],
                'certificate_reference' => ['', '', $reference],
                default => ['', '', ''],
            };
        }

        return [
            (string) $this->input('application_reference', ''),
            (string) $this->input('qualification_reference', ''),
            (string) $this->input('certificate_reference', ''),
        ];
    }
}
