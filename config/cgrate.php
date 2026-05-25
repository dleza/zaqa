<?php

return [
    /*
    |--------------------------------------------------------------------------
    | cGrate (Konik) Mobile Money Payments
    |--------------------------------------------------------------------------
    |
    | This configuration is used by the cGrate "Customer Payment" SOAP APIs:
    | - processCustomerPayment(transactionAmount, customerMobile, paymentReference)
    | - queryCustomerPayment(paymentReference)
    |
    | Integration uses manually rendered SOAP XML over HTTP (no PHP SOAP ext).
    |
    */

    'enabled' => (bool) env('CGRATE_ENABLED', false),

    'base_url' => env('CGRATE_BASE_URL', 'https://test.543.cgrate.co.zm'),
    'username' => env('CGRATE_USERNAME', ''),
    'password' => env('CGRATE_PASSWORD', ''),

    'timeout' => (int) env('CGRATE_TIMEOUT', 30),
    'connect_timeout' => (int) env('CGRATE_CONNECT_TIMEOUT', 10),
    'verify_ssl' => (bool) env('CGRATE_VERIFY_SSL', true),

    'poll_interval_seconds' => (int) env('CGRATE_POLL_INTERVAL_SECONDS', 10),
    'max_query_attempts' => (int) env('CGRATE_MAX_QUERY_ATTEMPTS', 30),
    'payment_expiry_minutes' => (int) env('CGRATE_PAYMENT_EXPIRY_MINUTES', 10),

    'default_currency' => env('CGRATE_DEFAULT_CURRENCY', 'ZMW'),

    /*
     * How to format transactionAmount for processCustomerPayment.
     * - kwacha_decimal: send a decimal string with 2dp (e.g. "10.00")
     * - minor_units: send the minor units integer (e.g. "1000" cents/ngwee)
     *
     * Default to kwacha_decimal until UAT proves otherwise.
     */
    'amount_mode' => env('CGRATE_AMOUNT_MODE', 'kwacha_decimal'),

    /*
     * How to normalize MSISDN values for cGrate.
     * - local: "097xxxxxxx"
     * - international_without_plus: "26097xxxxxxx"
     */
    'msisdn_format' => env('CGRATE_MSISDN_FORMAT', 'local'),

    'soap' => [
        'endpoint_path' => env('CGRATE_ENDPOINT_PATH', '/Konik/KonikWs'),
        'namespace' => env('CGRATE_SOAP_NAMESPACE', 'http://konik.cgrate.com'),

        // Postman collection uses `application/soap+xml` (even though the sample envelope is SOAP 1.1).
        // Keep this configurable in case your cGrate environment strictly requires `text/xml`.
        'content_type' => env('CGRATE_CONTENT_TYPE', 'application/soap+xml; charset=utf-8'),
    ],

    /*
     * Unknown/No-transaction codes may occur briefly immediately after initiation.
     * Keep the attempt pending for N query attempts before classifying as unknown/failed.
     */
    'unknown_fail_after_attempts' => (int) env('CGRATE_UNKNOWN_FAIL_AFTER_ATTEMPTS', 5),

    /*
     * Safety: Artisan test command should not run in production unless explicitly allowed.
     */
    'allow_test_command_in_production' => (bool) env('CGRATE_ALLOW_TEST_COMMAND_IN_PRODUCTION', false),
];
