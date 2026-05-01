<?php

namespace App\Http\Requests\Applicant;

use Illuminate\Foundation\Http\FormRequest;

class SaveWizardDeclarationsRequest extends FormRequest
{
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
            'accept_terms' => ['required', 'boolean', 'accepted'],
            'confirm_information_correct' => ['required', 'boolean', 'accepted'],
        ];
    }
}
