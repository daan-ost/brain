<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Upload Limits
    |--------------------------------------------------------------------------
    |
    | System-wide limits for file uploads and processing.
    | These are hard limits that apply regardless of page-specific settings.
    |
    */

    'limits' => [
        // Minimum available memory required for processing (in bytes)
        'min_memory' => env('UPLOAD_MIN_MEMORY', 100 * 1024 * 1024), // 100MB

        // Minimum available disk space required (in bytes)
        'min_disk_space' => env('UPLOAD_MIN_DISK_SPACE', 500 * 1024 * 1024), // 500MB
    ],
];
