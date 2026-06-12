<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    |
    | Control feature rollout and A/B testing with feature flags.
    | Set to true to enable, false to disable.
    |
    */

    'new_conversion_ui' => env('FEATURE_NEW_CONVERSION_UI', false),

    /*
    |--------------------------------------------------------------------------
    | Per-Slug Rollout
    |--------------------------------------------------------------------------
    |
    | Enable new UI for specific conversion pages only.
    | Useful for gradual rollout and testing.
    |
    */

    'new_ui_slugs' => env('FEATURE_NEW_UI_SLUGS', '') !== ''
        ? explode(',', env('FEATURE_NEW_UI_SLUGS', ''))
        : [],

    /*
    |--------------------------------------------------------------------------
    | User Testing
    |--------------------------------------------------------------------------
    |
    | Enable new UI for specific user IDs for testing.
    |
    */

    'new_ui_users' => env('FEATURE_NEW_UI_USERS', '') !== ''
        ? array_map('intval', explode(',', env('FEATURE_NEW_UI_USERS', '')))
        : [],

    /*
    |--------------------------------------------------------------------------
    | Demo CRUD
    |--------------------------------------------------------------------------
    |
    | Enable the demo CRUD feature to show a complete CRUD example
    | with list, filters, forms, status machine and tests.
    |
    */

    'demo_crud' => env('FEATURE_DEMO_CRUD', false),

    'demo_crud_form_mode' => env('DEMO_CRUD_FORM_MODE', 'page'),

    /*
    |--------------------------------------------------------------------------
    | Send Email Functionality
    |--------------------------------------------------------------------------
    |
    | Enable custom sender email configuration for organizations.
    | Allows organizations to send emails from their own domain.
    |
    */

    'send_email_functionality' => env('SEND_EMAIL_FUNCTIONALITY', false),

];
