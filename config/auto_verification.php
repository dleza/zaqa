<?php

return [
    'enabled' => (bool) env('AUTO_VERIFICATION_ENABLED', true),
    'threshold' => (int) env('AUTO_VERIFICATION_THRESHOLD', 70),
    'auto_issue_enabled' => (bool) env('AUTO_VERIFICATION_AUTO_ISSUE_ENABLED', false),
    'issuer_user_id' => env('AUTO_VERIFICATION_ISSUER_USER_ID'),
];
