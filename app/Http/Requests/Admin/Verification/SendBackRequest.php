<?php

namespace App\Http\Requests\Admin\Verification;

use Illuminate\Foundation\Http\FormRequest;

class SendBackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('verification.send_back') ?? false;
    }

    public function rules(): array
    {
        return [
            'comment' => ['required', 'string', 'min:3', 'max:5000'],
        ];
    }
}

