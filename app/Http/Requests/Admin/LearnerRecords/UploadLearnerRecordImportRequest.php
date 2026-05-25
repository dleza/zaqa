<?php

namespace App\Http\Requests\Admin\LearnerRecords;

use Illuminate\Foundation\Http\FormRequest;

class UploadLearnerRecordImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $u = $this->user();

        return (bool) ($u && $u->can('learner_records.import'));
    }

    public function rules(): array
    {
        return [
            'awarding_institution_id' => ['nullable', 'integer', 'exists:awarding_institutions,id'],
            'file' => ['required', 'file', 'max:12288', 'mimes:xlsx,xls,csv'],
        ];
    }
}

