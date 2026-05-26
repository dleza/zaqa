<?php

namespace App\Http\Requests\InstitutionApi\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreInstitutionLearnerRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_id' => ['nullable', 'string', 'max:100', 'required_without_all:certificate_no,nrc_number,passport_no'],
            'certificate_no' => ['nullable', 'string', 'max:100', 'required_without_all:student_id,nrc_number,passport_no'],
            'nrc_number' => ['nullable', 'string', 'max:50', 'required_without_all:student_id,certificate_no,passport_no'],
            'passport_no' => ['nullable', 'string', 'max:50', 'required_without_all:student_id,certificate_no,nrc_number'],

            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'other_names' => ['nullable', 'string', 'max:150'],
            'gender' => ['nullable', 'string', 'max:20'],

            'program_of_study' => ['required', 'string', 'max:255'],
            'year_awarded' => ['required', 'integer', 'min:1900', 'max:2100'],
            'award_date' => ['nullable', 'date'],
            'source_reference' => ['nullable', 'string', 'max:255'],
        ];
    }
}

