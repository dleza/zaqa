<?php

namespace App\Http\Requests\Applicant\Concerns;

trait ValidatesNamesAsOnQualificationDocument
{
    protected function prepareForValidation(): void
    {
        if ($this->has('names_as_on_qualification_document')) {
            $this->merge([
                'names_as_on_qualification_document' => trim((string) $this->input('names_as_on_qualification_document', '')),
            ]);
        }
    }

    /**
     * @return array<string, string>
     */
    protected function namesAsOnQualificationDocumentMessages(): array
    {
        return [
            'names_as_on_qualification_document.required' => 'Enter the names exactly as they appear on the qualification document.',
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function namesAsOnQualificationDocumentRules(): array
    {
        return [
            'names_as_on_qualification_document' => ['required', 'string', 'max:255'],
        ];
    }
}
