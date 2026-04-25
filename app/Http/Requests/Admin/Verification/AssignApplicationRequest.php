<?php

namespace App\Http\Requests\Admin\Verification;

use Illuminate\Foundation\Http\FormRequest;

class AssignApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('verification.assign') ?? false;
    }

    public function rules(): array
    {
        return [
            'assigned_to_user_id' => ['required', 'integer', 'exists:users,id'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

