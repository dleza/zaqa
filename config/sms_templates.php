<?php

/**
 * Centralized SMS templates. Each rendered message must not exceed SMS_MAX_LENGTH (159).
 * Placeholders use :name syntax.
 */
return [
    'application_submitted' => 'ZAQA: Application :application_number submitted. Check portal for qualification for updates.',

    'application_resubmitted' => 'ZAQA: Application :application_number resubmitted. Check portal for qualification for updates.',

    'payment_approved' => 'ZAQA: Payment confirmed for :application_number. Review application in the portal.',

    'payment_rejected' => 'ZAQA: Payment proof rejected for :application_number. Login for more details.',

    'application_sent_back' => 'ZAQA: Application :application_number sent back. Login to view comments.',

    'qualification_sent_back' => 'ZAQA: Qualification on :application_number needs amendments. Login to review.',

    'certificate_issued' => 'ZAQA: Certificate issued for :application_number. Login to download.',

    'verification_assigned' => 'ZAQA: Task assigned for :application_number. Login to review.',

    'activation_otp' => 'ZAQA OTP: :code. Expires :expires_at.',

    'password_reset_otp' => 'ZAQA password reset code: :code. Expires :expires_at.',
];
