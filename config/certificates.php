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
    'verify_url_base' => rtrim((string) env('ZAQA_CERT_VERIFY_BASE', 'https://verify.zaqa.gov.zm/certificates'), '/'),

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
