<?php

namespace App\Http\Requests\InstitutionApi\V1;

use Illuminate\Foundation\Http\FormRequest;

class BatchInstitutionLearnerRecordsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $max = (int) (config('institution_api.max_batch_size', 500) ?: 500);

        return [
            'records' => ['required', 'array', 'min:1', 'max:'.$max],
            'records.*.student_id' => ['nullable', 'string', 'max:100'],
            'records.*.certificate_no' => ['nullable', 'string', 'max:100'],
            'records.*.nrc_number' => ['nullable', 'string', 'max:50'],
            'records.*.passport_no' => ['nullable', 'string', 'max:50'],

            'records.*.first_name' => ['required', 'string', 'max:100'],
            'records.*.last_name' => ['required', 'string', 'max:100'],
            'records.*.other_names' => ['nullable', 'string', 'max:150'],
            'records.*.gender' => ['nullable', 'string', 'max:20'],

            'records.*.program_of_study' => ['required', 'string', 'max:255'],
            'records.*.year_awarded' => ['required', 'integer', 'min:1900', 'max:2100'],
            'records.*.award_date' => ['nullable', 'date'],
            'records.*.source_reference' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $records = $this->input('records');
            if (! is_array($records)) {
                return;
            }

            foreach ($records as $idx => $record) {
                if (! is_array($record)) {
                    continue;
                }

                $studentId = trim((string) ($record['student_id'] ?? ''));
                $certificateNo = trim((string) ($record['certificate_no'] ?? ''));
                $nrc = trim((string) ($record['nrc_number'] ?? ''));
                $passport = trim((string) ($record['passport_no'] ?? ''));

                if ($studentId === '' && $certificateNo === '' && $nrc === '' && $passport === '') {
                    $v->errors()->add("records.$idx.student_id", 'At least one identifier is required (student_id, certificate_no, nrc_number, passport_no).');
                }
            }
        });
    }
}
