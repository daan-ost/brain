<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Free Registration License Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the default license assigned to new users after
    | email confirmation. This allows easy modification of the free tier
    | settings without code changes.
    |
    */

    'free_registration' => [
        'slug' => env('FREE_LICENSE_SLUG', 'free-15'),
        'tier' => env('FREE_LICENSE_TIER', 'free'),
        'credits' => env('FREE_LICENSE_CREDITS', 15),
        'name' => 'Free User License',
    ],

];
