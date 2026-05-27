<?php

return [
    'bank_transfer' => [
        'pop_notification_emails' => array_values(array_filter(array_map(
            static fn ($email) => filter_var(trim((string) $email), FILTER_VALIDATE_EMAIL) ?: null,
            explode(',', (string) env('BANK_TRANSFER_POP_NOTIFICATION_EMAILS', ''))
        ))),
        'mail_attachment_max_bytes' => (int) env('BANK_TRANSFER_POP_EMAIL_ATTACHMENT_MAX_BYTES', 5 * 1024 * 1024),
    ],
];
