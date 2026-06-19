<?php

namespace App\Http\Requests\Applicant;

use App\Http\Requests\Concerns\ValidatesUserUploadSize;
use Illuminate\Foundation\Http\FormRequest;

class UploadPaymentProofRequest extends FormRequest
{
    use ValidatesUserUploadSize;

    public function authorize(): bool
    {
        $payment = $this->route('payment');

        return $this->user() && $payment && $this->user()->can('update', $payment->application);
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
