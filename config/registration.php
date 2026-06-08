<?php

return [
    'with_email' => (bool) env('REGISTER_WITH_EMAIL', true),
    'with_sms' => (bool) env('REGISTER_WITH_SMS', true),
];
