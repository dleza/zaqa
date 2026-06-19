<?php

$csv = static function (?string $value, array $default = []): array {
    $items = array_values(array_filter(array_map(
        static fn ($item) => trim((string) $item),
        explode(',', (string) $value)
    )));

    return $items === [] ? $default : $items;
};

return [
    /*
    |--------------------------------------------------------------------------
    | CyberSource REST Card Payments
    |--------------------------------------------------------------------------
    |
    | CyberSource is scoped to card payments only. The real REST API calls,
    | SDK client wiring, and Microform rendering will be added in later phases.
    |
    */

    'enabled' => (bool) env('CYBERSOURCE_ENABLED', false),

    'run_environment' => env('CYBERSOURCE_RUN_ENVIRONMENT', 'apitest.cybersource.com'),

    'merchant_id' => env('CYBERSOURCE_MERCHANT_ID', ''),
    'key_id' => env('CYBERSOURCE_KEY_ID', ''),
    'secret_key' => env('CYBERSOURCE_SECRET_KEY', ''),

    'auth_type' => env('CYBERSOURCE_AUTH_TYPE', 'JWT'),
    'jwt_key_type' => env('CYBERSOURCE_JWT_KEY_TYPE', 'SHARED_SECRET'),

    'target_origins' => $csv(env('CYBERSOURCE_TARGET_ORIGINS', '')),
    'allowed_card_networks' => $csv(env('CYBERSOURCE_ALLOWED_CARD_NETWORKS', 'VISA,MASTERCARD'), ['VISA', 'MASTERCARD']),
    'allowed_payment_types' => $csv(env('CYBERSOURCE_ALLOWED_PAYMENT_TYPES', 'CARD'), ['CARD']),

    'capture' => (bool) env('CYBERSOURCE_CAPTURE', true),

    'timeout' => (int) env('CYBERSOURCE_TIMEOUT', 30),

    'logging' => [
        'enabled' => (bool) env('CYBERSOURCE_LOGGING_ENABLED', false),
    ],
];
