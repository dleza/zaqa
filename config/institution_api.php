<?php

return [
    'max_batch_size' => (int) env('INSTITUTION_API_MAX_BATCH_SIZE', 500),
    'batch_sync_threshold' => (int) env('INSTITUTION_API_BATCH_SYNC_THRESHOLD', 50),
    'rate_limit_per_minute' => (int) env('INSTITUTION_API_RATE_LIMIT_PER_MINUTE', 60),

    // Documentation routes (Swagger UI / OpenAPI).
    'docs_enabled' => (bool) env('INSTITUTION_API_DOCS_ENABLED', true),
];

