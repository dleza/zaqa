<?php

namespace App\Http\Requests\Admin\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PreviewInstitutionPullLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('institution_api.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'student_id' => ['nullable', 'string', 'max:100'],
            'examination_number' => ['nullable', 'string', 'max:100'],
            'certificate_no' => ['nullable', 'string', 'max:100'],
            'nrc_number' => ['nullable', 'string', 'max:100'],
            'passport_no' => ['nullable', 'string', 'max:100'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'other_names' => ['nullable', 'string', 'max:255'],
            'program_of_study' => ['nullable', 'string', 'max:255'],
            'year_awarded' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'award_date' => ['nullable', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $fields = ['student_id', 'examination_number', 'certificate_no', 'nrc_number', 'passport_no'];
            $hasIdentifier = false;

            foreach ($fields as $field) {
                $value = trim((string) $this->input($field, ''));
                if ($value !== '') {
                    $hasIdentifier = true;
                    break;
                }
            }

            if (! $hasIdentifier) {
                $validator->errors()->add(
                    'student_id',
                    'Provide at least one identifier: student ID, examination number, certificate number, NRC, or passport.',
                );
            }
        });
    }
}
