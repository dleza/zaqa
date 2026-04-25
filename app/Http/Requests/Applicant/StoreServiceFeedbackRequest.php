<?php

namespace App\Http\Requests\Applicant;

use Illuminate\Foundation\Http\FormRequest;

class StoreServiceFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        $application = $this->route('application');

        if (! $this->user() || ! $application) {
            return false;
        }

        if (! $this->user()->can('view', $application)) {
            return false;
        }

        $status = (string) ($application->current_status?->value ?? (string) $application->current_status);

        return in_array($status, ['submitted', 'resubmitted'], true);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'rating_value' => ['required', 'integer', 'min:1', 'max:5'],
            'rating_label' => ['nullable', 'string', 'max:30'],
            'feedback_text' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

