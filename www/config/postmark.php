<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Postmark Templates Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Postmark Templates API integration.
    | This config manages server tokens and settings for template management.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Server Tokens
    |--------------------------------------------------------------------------
    |
    | Postmark server tokens for staging and production environments.
    | These tokens are used to authenticate with Postmark's API.
    |
    */

    'staging_server_token' => env('POSTMARK_STAGING_SERVER_TOKEN'),
    'production_server_token' => env('POSTMARK_PROD_SERVER_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Account Token
    |--------------------------------------------------------------------------
    |
    | Postmark Account API token for managing Sender Signatures and Domains.
    | This is different from the Server Token used for sending emails.
    |
    */

    'account_token' => env('POSTMARK_ACCOUNT_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Server IDs
    |--------------------------------------------------------------------------
    |
    | Postmark server IDs for staging and production environments.
    | These are used for pushing templates from staging to production.
    |
    */

    'staging_server_id' => env('POSTMARK_STAGING_SERVER_ID'),
    'production_server_id' => env('POSTMARK_PROD_SERVER_ID'),

    /*
    |--------------------------------------------------------------------------
    | Default From Email
    |--------------------------------------------------------------------------
    |
    | The default email address to use as the "from" address when sending
    | test emails. This should be a verified sender in your Postmark account.
    |
    */

    'from_email' => env('POSTMARK_FROM_EMAIL', 'noreply@example.com'),

    /*
    |--------------------------------------------------------------------------
    | API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Postmark API requests including timeouts and base URL.
    |
    */

    'api_base_url' => 'https://api.postmarkapp.com',
    'api_timeout' => 30,

    /*
    |--------------------------------------------------------------------------
    | Template Validation
    |--------------------------------------------------------------------------
    |
    | Settings for template validation and content checking.
    |
    */

    'validate_content_placeholder' => true,
    'require_text_body' => false,

    /*
    |--------------------------------------------------------------------------
    | Default Test Data
    |--------------------------------------------------------------------------
    |
    | Default template model data used for testing and validation.
    | You can override this data when testing specific templates.
    |
    */

    'default_test_data' => [
        'product_url' => 'https://example.com/product',
        'product_name' => 'Your Product',
        'company_name' => 'Your Company',
        'company_address' => '123 Main St, City, State 12345',
        'support_email' => 'support@example.com',
        'support_url' => 'https://example.com/support',
        'user_name' => 'John Doe',
        'user_email' => 'john.doe@example.com',
        'action_url' => 'https://example.com/action',
        'login_url' => 'https://example.com/login',
        'invoice_date' => now()->format('F j, Y'),
        'due_date' => now()->addDays(30)->format('F j, Y'),
        'total_amount' => '$99.00',
        'trial_end_date' => now()->addDays(30)->format('F j, Y'),
        'sender_name' => 'The Team',
        'year' => now()->year,
    ],

];
