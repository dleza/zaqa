<?php

namespace App\Http\Requests\Admin\Verification;

use Illuminate\Foundation\Http\FormRequest;

class Level2ReturnToLevel1Request extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('verification.level2.review') ?? false;
    }

    public function rules(): array
    {
        return [
            'comment' => ['required', 'string', 'min:3', 'max:10000'],
        ];
    }
}

