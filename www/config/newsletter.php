<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AWS SES Configuration for Newsletters
    |--------------------------------------------------------------------------
    |
    | Newsletter emails are sent via Amazon SES in eu-west-1 (Ireland)
    | for GDPR compliance. This is separate from the default SES region.
    |
    */

    'ses' => [
        'region' => env('AWS_SES_REGION', 'eu-west-1'),
        'configuration_set' => env('SES_CONFIGURATION_SET', 'newsletter-tracking'),
        'tracking_domain' => env('SES_TRACKING_DOMAIN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Batch Processing Configuration
    |--------------------------------------------------------------------------
    |
    | Control how newsletters are sent in batches to avoid overwhelming
    | the mail server and to allow pausing/resuming.
    |
    */

    'batch_size' => (int) env('NEWSLETTER_BATCH_SIZE', 100),
    'batch_delay_seconds' => (int) env('NEWSLETTER_BATCH_DELAY', 10),
    'batch_insert_size' => 500,

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    |
    | Maximum number of retry attempts and backoff time between retries.
    |
    */

    'max_attempts' => 3,
    'backoff_seconds' => 60,
    'unsubscribe_rate_limit' => env('NEWSLETTER_UNSUBSCRIBE_RATE_LIMIT', 10),

    /*
    |--------------------------------------------------------------------------
    | Send Limits
    |--------------------------------------------------------------------------
    |
    | Available send limit options for the admin dropdown.
    |
    */

    'send_limits' => [
        10 => 'Eerste 10 ontvangers',
        100 => 'Eerste 100 ontvangers',
        1000 => 'Eerste 1000 ontvangers',
        '' => 'Alle ontvangers',
    ],

    /*
    |--------------------------------------------------------------------------
    | Email Configuration
    |--------------------------------------------------------------------------
    |
    | From address and name for newsletter emails.
    |
    */

    'from' => [
        'address' => env('NEWSLETTER_FROM_ADDRESS', env('MAIL_FROM_ADDRESS')),
        'name' => env('NEWSLETTER_FROM_NAME', env('MAIL_FROM_NAME')),
    ],

    'brand_color' => env('NEWSLETTER_BRAND_COLOR', '#53b3ae'),

];
