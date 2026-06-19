<?php

namespace App\Http\Requests\Applicant;

use App\Domain\Applications\ApplicationNotificationContact;
use App\Http\Requests\Applicant\Concerns\ValidatesApplicationNotificationContact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateInstitutionalMultipleApplicationRequest extends FormRequest
{
    use ValidatesApplicationNotificationContact;

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
            'institution_reference' => ['nullable', 'string', 'max:255'],
            'notification_contact_mode' => ['nullable', 'string', 'in:'.ApplicationNotificationContact::MODE_APPLICANT_ACCOUNT.','.ApplicationNotificationContact::MODE_ADDITIONAL_EMAIL],
            'notification_contact_email' => ['nullable', 'email', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateApplicationNotificationContact($validator, 'self');
        });
    }
}
