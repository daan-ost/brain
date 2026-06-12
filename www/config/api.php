<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API Version 1 Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration options for the REST API v1.
    |
    */

    // Rate limiting - requests per minute per user
    'rate_limit_per_minute' => env('API_RATE_LIMIT_PER_MINUTE', 100),

    // Test mode - when enabled, provides additional debugging information
    'test_mode' => env('API_TEST_MODE', false),

    // Documentation URL
    'documentation_url' => env('API_DOCUMENTATION_URL', '/api/docs/v1'),

];
