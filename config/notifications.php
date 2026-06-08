<?php

return [
    'queues' => [
        'mail' => env('NOTIFICATIONS_MAIL_QUEUE', 'notifications'),
        'sms' => env('NOTIFICATIONS_SMS_QUEUE', 'notifications'),
        'listeners' => env('NOTIFICATIONS_LISTENER_QUEUE', 'default'),
    ],
];
