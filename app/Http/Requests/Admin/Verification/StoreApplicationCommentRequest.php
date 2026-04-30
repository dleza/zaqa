<?php

namespace App\Http\Requests\Admin\Verification;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApplicationCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('verification.pool.view') ?? false;
    }

    public function rules(): array
    {
        return [
            'body' => [
                'required',
                'string',
                'max:5000',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $trimmed = trim((string) $value);
                    if ($trimmed === '') {
                        $fail('Comment is required.');
                        return;
                    }

                    if (mb_strlen($trimmed) < 3) {
                        $fail('Comment must be at least 3 characters.');
                    }
                },
            ],
            'visibility' => ['required', 'string', Rule::in(['internal', 'applicant_visible'])],
            'type' => ['nullable', 'string', 'max:100'],
        ];
    }
}
