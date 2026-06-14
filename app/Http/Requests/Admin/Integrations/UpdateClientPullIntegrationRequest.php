<?php

namespace App\Http\Requests\Admin\Integrations;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClientPullIntegrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('institution_api.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'is_active' => ['required', 'boolean'],
            'supports_pull' => ['required', 'boolean'],
            'lookup_url' => ['nullable', 'url', 'max:2048'],
            'auth_type' => ['nullable', 'string', 'max:50', Rule::in(['none', 'bearer_token', 'basic'])],
            'bearer_token' => ['nullable', 'string', 'max:4096'],
            'basic_username' => ['nullable', 'string', 'max:255'],
            'basic_password' => ['nullable', 'string', 'max:255'],
            'request_method' => ['required', 'string', 'max:10', Rule::in(['GET', 'POST'])],
            'timeout_seconds' => ['required', 'integer', 'min:3', 'max:60'],
            'retry_attempts' => ['required', 'integer', 'min:0', 'max:5'],
            'rate_limit_per_minute' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'driver' => ['nullable', 'string', 'max:50', Rule::in(['generic_rest'])],
        ];
    }
}
