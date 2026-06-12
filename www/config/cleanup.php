<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Retention Period
    |--------------------------------------------------------------------------
    |
    | The default number of days to keep conversion results before cleanup.
    | This can be overridden per organization via organization_domains.max_storage_days
    |
    */
    'default_retention_days' => env('STORAGE_RETENTION_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | Upload Retention
    |--------------------------------------------------------------------------
    |
    | Uploaded files are temporary and should be removed quickly after processing.
    | Default: 60 minutes (1 hour)
    |
    */
    'upload_retention_minutes' => 60,

    /*
    |--------------------------------------------------------------------------
    | Failed Conversion Retention
    |--------------------------------------------------------------------------
    |
    | Keep failed conversions for debugging purposes before cleanup.
    | Default: 7 days
    |
    */
    'failed_conversion_days' => 7,

    /*
    |--------------------------------------------------------------------------
    | Temp File Retention
    |--------------------------------------------------------------------------
    |
    | Temporary files (cover pages, extraction temp, etc.) retention.
    | Default: 1 hour
    |
    */
    'temp_hours' => 1,

    /*
    |--------------------------------------------------------------------------
    | Workflow Temp Retention
    |--------------------------------------------------------------------------
    |
    | Workflow temporary files should be cleaned immediately after completion.
    | This is the fallback for orphaned workflow temp files.
    | Default: 24 hours
    |
    */
    'workflow_temp_hours' => 24,

    /*
    |--------------------------------------------------------------------------
    | Storage Paths
    |--------------------------------------------------------------------------
    |
    | Define the storage paths for different file types.
    |
    */
    'paths' => [
        'uploads' => 'uploads',
        'converted' => 'converted',
        'temp' => 'temp',
        'workflow_results' => 'workflow_results',
        'cover_temp' => 'temp/cover',
    ],
];
