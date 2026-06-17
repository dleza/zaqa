<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Allowed certificate subject grades
    |--------------------------------------------------------------------------
    |
    | Numeric grades 1–9 and letter grades A–Z. Stored and validated as strings.
    |
    */
    'allowed_grades' => array_merge(
        array_map('strval', range(1, 9)),
        range('A', 'Z'),
    ),
];
