<?php

namespace App\Http\Requests\Applicant\Concerns;

use App\Domain\Applications\ApplicationNotificationContact;
use Illuminate\Validation\Validator;

trait ValidatesApplicationNotificationContact
{
    protected function validateApplicationNotificationContact(Validator $validator, string $submittingFor): void
    {
        if ($submittingFor !== 'other') {
            return;
        }

        $mode = (string) $this->input('notification_contact_mode', ApplicationNotificationContact::MODE_APPLICANT_ACCOUNT);
        if (! in_array($mode, [
            ApplicationNotificationContact::MODE_APPLICANT_ACCOUNT,
            ApplicationNotificationContact::MODE_ADDITIONAL_EMAIL,
        ], true)) {
            $validator->errors()->add('notification_contact_mode', 'Select who should receive application updates.');
        }

        if ($mode === ApplicationNotificationContact::MODE_ADDITIONAL_EMAIL) {
            $email = trim((string) $this->input('additional_notification_email', ''));
            if ($email === '') {
                $validator->errors()->add('additional_notification_email', 'Additional recipient email is required.');
            } elseif (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $validator->errors()->add('additional_notification_email', 'Enter a valid email address.');
            }
        }
    }
}
