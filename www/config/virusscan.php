<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Virus Scanning Feature Flag
    |--------------------------------------------------------------------------
    |
    | Enable or disable virus scanning globally. When disabled, all uploads
    | bypass scanning and proceed directly to processing.
    |
    */
    'enabled' => env('VIRUSSCAN_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | ClamAV Socket Connection
    |--------------------------------------------------------------------------
    |
    | Unix socket path for ClamAV daemon communication. The default path
    | is standard for Ubuntu/Debian installations.
    |
    */
    'socket' => env('CLAMAV_SOCKET', '/var/run/clamav/clamd.ctl'),

    /*
    |--------------------------------------------------------------------------
    | Scan Timeout
    |--------------------------------------------------------------------------
    |
    | Maximum time in seconds to wait for a scan result. Large files may
    | need more time. The scan job will retry on timeout.
    |
    */
    'timeout' => env('CLAMAV_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Failure Policy
    |--------------------------------------------------------------------------
    |
    | What happens when scanning fails (timeout, ClamAV down, etc.):
    | - 'open': Allow file to proceed (log the failure)
    | - 'closed': Block file until scan succeeds
    |
    | Recommendation: Use 'open' initially, switch to 'closed' after testing.
    |
    */
    'fail_policy' => env('VIRUSSCAN_FAIL_POLICY', 'open'),

    /*
    |--------------------------------------------------------------------------
    | Quarantine Settings
    |--------------------------------------------------------------------------
    |
    | Infected files are moved to quarantine for review. The directory
    | must be outside the public webroot for security.
    |
    */
    'quarantine_path' => storage_path('app/quarantine'),
    'quarantine_retention_days' => env('VIRUSSCAN_QUARANTINE_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | User Tier Restrictions
    |--------------------------------------------------------------------------
    |
    | Optionally restrict virus scanning to specific user tiers.
    | Set to null to scan for all users, or specify tiers like:
    | ['business', 'premium']
    |
    */
    'required_tier' => null,

    /*
    |--------------------------------------------------------------------------
    | Alerting Configuration
    |--------------------------------------------------------------------------
    |
    | Configure notifications when viruses are detected or the scanner
    | experiences issues.
    |
    */
    'alerting' => [
        'enabled' => env('VIRUSSCAN_ALERTING_ENABLED', true),
        'channels' => ['mail'], // Available: mail, slack
        'recipients' => env('VIRUSSCAN_ALERT_EMAIL'),
        'slack_webhook' => env('VIRUSSCAN_SLACK_WEBHOOK'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for the scan job retry mechanism.
    |
    */
    'retry' => [
        'max_attempts' => 3,
        'backoff' => [60, 300, 900], // 1min, 5min, 15min
    ],

    /*
    |--------------------------------------------------------------------------
    | Scan Statistics
    |--------------------------------------------------------------------------
    |
    | Enable detailed logging for scan performance monitoring.
    |
    */
    'log_performance' => env('VIRUSSCAN_LOG_PERFORMANCE', true),
];
