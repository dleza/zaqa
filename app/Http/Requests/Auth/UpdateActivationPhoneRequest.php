<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateActivationPhoneRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = $this->user()?->id;

        return [
            'phone_primary' => [
                'required',
                'string',
                'max:30',
                Rule::unique('users', 'phone_primary')->ignore($userId),
            ],
        ];
    }
}

