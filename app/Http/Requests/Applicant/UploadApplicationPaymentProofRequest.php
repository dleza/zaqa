<?php

namespace App\Http\Requests\Applicant;

use App\Http\Requests\Concerns\ValidatesUserUploadSize;
use Illuminate\Foundation\Http\FormRequest;

class UploadApplicationPaymentProofRequest extends FormRequest
{
    use ValidatesUserUploadSize;

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
        return [
            'file' => ['required', 'file', 'max:'.$this->userUploadMaxKb(), 'mimes:pdf,jpg,jpeg,png,webp'],
        ];
    }

    public function messages(): array
    {
        return $this->userUploadValidationMessages();
    }
}
