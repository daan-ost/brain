<?php

return [
    /*
    |--------------------------------------------------------------------------
    | DeepL API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for DeepL translation API integration.
    |
    */

    'api_key' => env('DEEPL_API_KEY'),

    // API endpoint (free or pro)
    'api_url' => env('DEEPL_API_URL', 'https://api-free.deepl.com/v2'),

    // Formality: 'default', 'prefer_more', 'prefer_less'
    'formality' => env('DEEPL_FORMALITY', 'prefer_more'),

    // Default source language
    'source_lang' => env('TRANSLATE_DEFAULT_FROM', 'en'),

    // Target languages (comma-separated)
    'target_langs' => explode(',', env('TRANSLATE_TARGETS', 'nl')),

    // Timeout for API requests (seconds)
    'timeout' => env('DEEPL_TIMEOUT', 30),

    // Enable translation caching
    'cache_enabled' => env('DEEPL_CACHE_ENABLED', true),

    // Cache TTL in seconds (6 months default)
    'cache_ttl' => env('DEEPL_CACHE_TTL', 15552000),

    // Glossary IDs per language pair
    'glossary_en_nl' => env('DEEPL_GLOSSARY_EN_NL'),

    // Fields to exclude from translation
    // These fields should NEVER be translated as they affect routing, URLs, and technical functionality
    'exclude_fields' => env('TRANSLATE_EXCLUDE_FIELDS', 'slug,handle,id,template,blueprint,url,uri,path,route,redirect_url,canonical_url,permalink'),
];
