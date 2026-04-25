<?php

namespace App\Http\Requests\Applicant;

use Illuminate\Foundation\Http\FormRequest;

class UpsertSubjectResultsRequest extends FormRequest
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
            'subject_results' => ['required', 'array', 'min:1'],
            'subject_results.*.subject_name' => ['required', 'string', 'max:255'],
            'subject_results.*.grade' => ['required', 'string', 'max:50'],
        ];
    }
}

