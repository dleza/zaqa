<?php

return [
    // Optional system actor user id used for category-based auto-assignment when no explicit actor is provided.
    // If null/invalid, the first Super Admin user is used.
    'actor_user_id' => env('AUTO_ASSIGNMENT_ACTOR_USER_ID'),
];

