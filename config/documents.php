<?php

return [
    /*
     * Maximum upload size in kilobytes for applicant/L1/L2 user documents.
     * Prefer UserUploadLimit helper; kept for backward compatibility.
     */
    'max_upload_kb' => (int) env('ZAQA_UPLOAD_MAX_FILE_SIZE_MB', 3) * 1024,

    /*
     * Strict MIME allowlist for applicant uploads.
     */
    'allowed_mimetypes' => [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
    ],
];

