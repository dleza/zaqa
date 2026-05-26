<?php

namespace App\Http\Requests\InstitutionApi\V1;

use Illuminate\Foundation\Http\FormRequest;

class SearchInstitutionLearnerRecordsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_id' => ['nullable', 'string', 'max:100'],
            'certificate_no' => ['nullable', 'string', 'max:100'],
            'nrc_number' => ['nullable', 'string', 'max:50'],
            'passport_no' => ['nullable', 'string', 'max:50'],
            'year_awarded' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'program_of_study' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}

