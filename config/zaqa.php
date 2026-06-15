<?php

return [
    'super_admin' => [
        'name' => env('ZAQA_SUPER_ADMIN_NAME', 'ZAQA Super Admin'),
        'email' => env('ZAQA_SUPER_ADMIN_EMAIL', 'superadmin@zaqa.gov.zm'),
        'phone' => env('ZAQA_SUPER_ADMIN_PHONE', '260000000000'),
        'password' => env('ZAQA_SUPER_ADMIN_PASSWORD', 'ChangeMe@2026'),
    ],
    'organization' => [
        'name' => env('ZAQA_ORG_NAME', 'Zambia Qualifications Authority.'),
        'legal_name' => env('ZAQA_ORG_LEGAL_NAME', 'ZAMBIA QUALIFICATIONS AUTHORITY'),
        'address' => env('ZAQA_ORG_ADDRESS', '2886 Finsbury Park, Kabwe Round-about, Lusaka.'),
        'address_line_1' => env('ZAQA_ORG_ADDRESS_LINE_1', 'Finsbury Park Ground Floor'),
        'address_line_2' => env('ZAQA_ORG_ADDRESS_LINE_2', 'Kabwe Roundabout'),
        'address_line_3' => env('ZAQA_ORG_ADDRESS_LINE_3', 'Lusaka, Zambia'),
        'phone' => env('ZAQA_ORG_PHONE', '(913) - 262-689-6253'),
        'tel' => env('ZAQA_ORG_TEL', '+260 211 843 050'),
        'fax' => env('ZAQA_ORG_FAX', '+260 211 843 050'),
        'email' => env('ZAQA_ORG_EMAIL', 'info@zaqa.gov.zm'),
        'website' => env('ZAQA_ORG_WEBSITE', 'https://www.zaqa.gov.zm/'),
    ],
    'receipt' => [
        'verify_url_base' => rtrim((string) env(
            'RECEIPT_VERIFICATION_BASE_URL',
            env('ZAQA_RECEIPT_VERIFY_BASE', rtrim((string) env('APP_URL', 'http://localhost'), '/').'/receipts')
        ), '/'),
    ],
];

