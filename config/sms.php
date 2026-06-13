<?php

return [
    'enabled' => (bool) env('SMS_ENABLED', false),

    'provider' => env('SMS_PROVIDER', 'log'),

    'max_length' => (int) env('SMS_MAX_LENGTH', 159),

    'low_balance_threshold' => (int) env('SMS_LOW_BALANCE_THRESHOLD', 100),

    'critical_balance_threshold' => (int) env('SMS_CRITICAL_BALANCE_THRESHOLD', 10),

    'alert_emails' => array_values(array_filter(array_map(
        static fn (string $email): string => trim($email),
        explode(',', (string) env('SMS_ALERT_EMAILS', '')),
    ), static fn (string $email): bool => $email !== '')),

    /*
    | Template keys whose placeholder values must not appear in admin SMS log views.
    | Placeholder names match sms_templates.php (:code, :expires_at, etc.).
    */
    'admin_redaction' => [
        'activation_otp' => ['code'],
    ],

    'zamtel' => [
        'base_url' => env('ZAMTEL_SMS_BASE_URL', 'https://bulksms.zamtel.co.zm'),
        'api_key' => env('ZAMTEL_SMS_API_KEY', ''),
        'sender_id' => env('ZAMTEL_SMS_SENDER_ID', env('SMS_FROM', 'ZAQA')),
        'timeout' => (int) env('ZAMTEL_SMS_TIMEOUT', 30),
        'connect_timeout' => (int) env('ZAMTEL_SMS_CONNECT_TIMEOUT', 10),
        'verify_ssl' => (bool) env('ZAMTEL_SMS_VERIFY_SSL', true),
    ],
];
