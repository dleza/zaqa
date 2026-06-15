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
        'address' => env('ZAQA_ORG_ADDRESS', '2886 Finsbury Park, Kabwe Round-about, Lusaka.'),
        'phone' => env('ZAQA_ORG_PHONE', '(913) - 262-689-6253'),
        'email' => env('ZAQA_ORG_EMAIL', 'info@zaqa.gov.zm'),
    ],
];

