<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class RevokeQualificationCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('certificates.revoke') ?? false;
    }

    public function rules(): array
    {
        return [
            'revocation_reason' => ['required', 'string', 'max:2000'],
            'revocation_public_note' => ['nullable', 'string', 'max:1000'],
            'confirm' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'revocation_reason.required' => 'Provide a reason for revoking this certificate.',
            'confirm.accepted' => 'Confirm that you understand this certificate will no longer verify as valid publicly.',
        ];
    }
}
