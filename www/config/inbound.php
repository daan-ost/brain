<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Inbound Email Feature
    |--------------------------------------------------------------------------
    |
    | Enable or disable the inbound email processing feature globally.
    |
    */
    'enabled' => env('INBOUND_EMAIL_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Inbound Email Domain
    |--------------------------------------------------------------------------
    |
    | The domain that will receive inbound emails. This should match your
    | Postmark inbound domain configuration.
    | Example: inbound.example.com
    |
    */
    'email_domain' => env('INBOUND_EMAIL_DOMAIN', 'inbound.example.com'),

    /*
    |--------------------------------------------------------------------------
    | Postmark Webhook Token
    |--------------------------------------------------------------------------
    |
    | Optional but recommended: A secret token to authenticate webhook requests
    | from Postmark. This helps prevent unauthorized access to the webhook.
    |
    */
    'webhook_token' => env('POSTMARK_INBOUND_WEBHOOK_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Available Actions
    |--------------------------------------------------------------------------
    |
    | Define which email processing actions are available for users.
    | Each action will generate a unique email address per user.
    | Example: merge+abc123@inbound.example.com
    |
    */
    'available_actions' => [
        'merge',
        'convert',
    ],

    /*
    |--------------------------------------------------------------------------
    | Action Descriptions
    |--------------------------------------------------------------------------
    |
    | Multilingual descriptions for each available action.
    | These will be shown to users in their preferences.
    |
    */
    'action_descriptions' => [
        'merge' => [
            'en' => 'Merge all attachments into a single document',
            'nl' => 'Voeg alle bijlagen samen tot één document',
        ],
        'convert' => [
            'en' => 'Convert attachments to the specified format',
            'nl' => 'Converteer bijlagen naar het opgegeven formaat',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Processing Limits
    |--------------------------------------------------------------------------
    |
    | Configure limits for email and attachment processing.
    |
    */
    'limits' => [
        'max_attachment_size_mb' => env('INBOUND_MAX_ATTACHMENT_SIZE_MB', 25),
        'max_attachments_per_email' => env('INBOUND_MAX_ATTACHMENTS', 20),
        'max_email_size_mb' => env('INBOUND_MAX_EMAIL_SIZE_MB', 50),
        'retention_days' => env('INBOUND_RETENTION_DAYS', 90),
        'result_retention_days' => env('INBOUND_RESULT_RETENTION_DAYS', 7), // Days to keep output files before cleanup
        'max_processing_attempts' => env('INBOUND_MAX_PROCESSING_ATTEMPTS', 3),
        'webhook_timeout_seconds' => env('INBOUND_WEBHOOK_TIMEOUT', 30),
        'max_subject_length' => 500,
        'max_body_length_chars' => 1000000,
        'rate_limit_per_ip_per_minute' => env('INBOUND_RATE_LIMIT', 30),
        'max_email_hops' => 50,
        'max_nested_email_depth' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed MIME Types
    |--------------------------------------------------------------------------
    |
    | Define which file types are allowed for attachments.
    |
    */
    'allowed_mime_types' => [
        'image/*',
        'application/pdf',
        'text/*',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.*',
        'application/vnd.ms-*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Configuration
    |--------------------------------------------------------------------------
    |
    | Configure where inbound email attachments are stored.
    |
    */
    'storage' => [
        'disk' => env('INBOUND_STORAGE_DISK', 'local'),
        'path' => env('INBOUND_STORAGE_PATH', 'inbound-emails'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Configure rate limiting for the inbound webhook endpoint.
    |
    */
    'rate_limiting' => [
        'enabled' => env('INBOUND_RATE_LIMITING_ENABLED', true),
        'key' => 'inbound-webhook',
        'max_attempts' => env('INBOUND_RATE_LIMIT', 30),
        'decay_minutes' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Virus Scanning (Prepared for ClamAV)
    |--------------------------------------------------------------------------
    |
    | These settings prepare the system for future ClamAV integration.
    | Virus scanning is not yet active but the infrastructure is in place.
    |
    */
    'virus_scanning' => [
        'enabled' => env('INBOUND_VIRUS_SCANNING_ENABLED', false),
        'quarantine_infected' => true,
        'notify_admin_on_virus' => true,
    ],
];
