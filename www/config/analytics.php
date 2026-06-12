<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Client-side Tracking Kill-switch
    |--------------------------------------------------------------------------
    |
    | Set to false to disable all client-side JavaScript tracking.
    | Server-side analytics will continue to work.
    | Use this to quickly disable tracking without a deploy.
    |
    */
    'client_tracking_enabled' => env('ANALYTICS_CLIENT_TRACKING', true),

    /*
    |--------------------------------------------------------------------------
    | Session Actions Limit
    |--------------------------------------------------------------------------
    |
    | Maximum number of actions to store per session in the session_actions
    | JSON column. This prevents database bloat from highly active sessions.
    |
    */
    'max_session_actions' => 50,

    /*
    |--------------------------------------------------------------------------
    | Cleanup Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for automatic cleanup of old analytics data.
    | - sessions_older_than_days: Delete complete sessions after X days
    | - events_older_than_days: Delete/archive individual events after X days
    |
    */
    'cleanup' => [
        'sessions_older_than_days' => 7,
        'events_older_than_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Maximum API requests per minute per session_id.
    | Prevents abuse and reduces server load.
    |
    */
    'rate_limit_per_minute' => 20,
];
