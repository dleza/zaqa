<?php

return [
    'queues' => [
        'high' => env('PAYMENTS_QUEUE_HIGH', 'payments-high'),
        'polling' => env('PAYMENTS_QUEUE', 'payments'),
    ],

    'cgrate' => [
        'callback_enabled' => (bool) env('CGRATE_CALLBACK_ENABLED', false),
        'callback_token' => env('CGRATE_CALLBACK_TOKEN'),
        'callback_allowed_ips' => array_values(array_filter(array_map(
            static fn ($ip) => trim((string) $ip),
            explode(',', (string) env('CGRATE_CALLBACK_ALLOWED_IPS', ''))
        ))),
    ],

    'bank_transfer' => [
        'pop_notification_emails' => array_values(array_filter(array_map(
            static fn ($email) => filter_var(trim((string) $email), FILTER_VALIDATE_EMAIL) ?: null,
            explode(',', (string) env('BANK_TRANSFER_POP_NOTIFICATION_EMAILS', ''))
        ))),
        'mail_attachment_max_bytes' => (int) env('BANK_TRANSFER_POP_EMAIL_ATTACHMENT_MAX_BYTES', 5 * 1024 * 1024),
        'deposit_account' => [
            'bank_name' => env('BANK_TRANSFER_BANK_NAME', 'Indo Zambia Bank'),
            'account_name' => env('BANK_TRANSFER_ACCOUNT_NAME', 'Zambia Qualifications Authority'),
            'account_number' => env('BANK_TRANSFER_ACCOUNT_NUMBER', '0052020000027'),
            'branch_code' => env('BANK_TRANSFER_BRANCH_CODE', '090005'),
            'branch_name' => env('BANK_TRANSFER_BRANCH_NAME', 'NORTH END BRANCH'),
        ],
    ],
];
