<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
        'message_stream_id' => env('POSTMARK_STREAM_TRANSACTIONAL', 'outbound'),
        'webhook_secret' => env('POSTMARK_WEBHOOK_SECRET'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_SES_REGION', 'eu-west-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'convertapi' => [
        'secret' => env('CONVERTAPI_SECRET'),
        'timeout' => env('CONVERTAPI_TIMEOUT', 15), // seconds (minimum 10, we use 15)

    ],

    'mollie' => [
        'api_key' => env('MOLLIE_API_KEY'),
        'profile_id' => env('MOLLIE_PROFILE_ID'),
        'webhook_url' => env('MOLLIE_WEBHOOK_URL'),

        // Webhook security: IP whitelisting + optional HMAC signature verification
        // See: https://docs.mollie.com/overview/webhooks#webhook-security
        'webhook_ips' => env('MOLLIE_WEBHOOK_IPS', '87.233.217.110,87.233.217.111,87.233.217.242,87.233.217.243'),

        // Locale mapping for Mollie payment pages
        'locales' => [
            'NL' => 'nl_NL',
            'DE' => 'de_DE',
            'FR' => 'fr_FR',
            'ES' => 'es_ES',
            'IT' => 'it_IT',
            'BE' => 'nl_BE',
        ],
        'default_locale' => 'en_US',
    ],

    'stripe' => [
        'public_key' => env('STRIPE_PUBLIC_KEY'),
        'secret_key' => env('STRIPE_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'webhook_url' => env('STRIPE_WEBHOOK_URL'),
        'api_version' => '2025-04-30.basil',
    ],

    'default_payment_provider' => env('PAYMENT_DEFAULT_PROVIDER', 'mollie'),

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
    ],

    'ipregistry' => [
        'key' => env('IPREGISTRY_SECRET'),
        'url' => env('IPREGISTRY_URL', 'https://api.ipregistry.co'),
    ],

    'google' => [
        'analytics_id' => env('GA4_MEASUREMENT_ID'),

        // OAuth (Socialite)
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => env('GOOGLE_REDIRECT_URI'),
    ],

    'ai' => [
        'internal_token' => env('AI_INTERNAL_TOKEN'),
    ],

    'trustpilot' => [
        'afs_email' => env('TRUSTPILOT_AFS_EMAIL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Externe binaries (absolute paden)
    |--------------------------------------------------------------------------
    |
    | Onder MAMP/php-fpm bevat de PATH vaak niet /opt/homebrew/bin, waardoor een
    | exec()/shell_exec() op bare naam stilzwijgend faalt in webserver-context.
    | App\Support\BinaryResolver lost deze binaries absoluut op; een env-override
    | hier heeft voorrang op de standaard zoekpaden (alleen nodig bij niet-standaard
    | locaties). Leeg laten = zoekpaden afzoeken.
    |
    */
    'binaries' => [
        'dig'     => env('DIG_BINARY'),
        'timeout' => env('TIMEOUT_BINARY'),
        'nproc'   => env('NPROC_BINARY'),
        'grep'    => env('GREP_BINARY'),
        'uptime'  => env('UPTIME_BINARY'),
    ],

];
