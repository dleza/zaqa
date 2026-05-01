<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Wizard step 3 — declarations only (not qualification / institution consent)
    |--------------------------------------------------------------------------
    */
    'declarations' => [
        'page_intro' => 'Before payment, please confirm the following. This is separate from qualification-specific consent (e.g. foreign institution forms) which you complete when adding each qualification.',
        'terms_title' => 'Terms and use of the service',
        'terms_body' => <<<'TEXT'
By using the ZAQA Qualifications Application Portal you agree to provide accurate information, to supply genuine supporting documents, and to cooperate with verification requests. ZAQA may reject applications that are incomplete, misleading, or fraudulent. Fees and processing times are as published for your application type. You are responsible for keeping your login details secure.
TEXT
        ,
        'accept_terms_label' => 'I have read and agree to the terms above.',
        'confirm_accuracy_label' => 'I confirm that the information and documents I have provided in this application are true, complete, and correct to the best of my knowledge.',
    ],
];
