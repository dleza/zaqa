<?php

namespace App\Http\Requests\Admin\Verification;

use Illuminate\Foundation\Http\FormRequest;

class IssueQualificationCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('verification.certificate.issue') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reissue' => ['sometimes', 'boolean'],
        ];
    }
}
