<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public verification URL base (no trailing slash)
    |--------------------------------------------------------------------------
    |
    | QR codes on CVEQ PDFs point here with /{token}. Override via env in production.
    |
    */
    'verify_url_base' => rtrim((string) env(
        'CERTIFICATE_VERIFICATION_BASE_URL',
        env('ZAQA_CERT_VERIFY_BASE', rtrim((string) env('APP_URL', 'http://localhost'), '/').'/certificates')
    ), '/'),

    'watermark_enabled' => (bool) env('CERTIFICATE_WATERMARK_ENABLED', true),

    'coat_of_arms_path' => (string) env(
        'CERTIFICATE_COAT_OF_ARMS_PATH',
        'resources/images/certificates/coat_of_arms_watermark.png'
    ),

    'director_general_name' => env('ZAQA_DIRECTOR_GENERAL_NAME', 'MERCY M. NGOMA'),

    'director_general_title' => env('ZAQA_DIRECTOR_GENERAL_TITLE', 'Director General'),

    /*
    |--------------------------------------------------------------------------
    | Legal statement template (optional placeholders use Blade-style {{ }} in code)
    |--------------------------------------------------------------------------
    */
    'recognition_act_clause' => env(
        'ZAQA_CERT_ACT_CLAUSE',
        'A registered and recognised institution under applicable laws of the Republic of Zambia.'
    ),
];
