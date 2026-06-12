<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Google Gemini API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Google Gemini AI integration for PDF editing.
    |
    */

    'api_key' => env('GEMINI_API_KEY'),

    // API endpoint (v1 for stable models)
    'api_url' => env('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1'),

    // Model to use (gemini-2.0-flash is fast and capable)
    'model' => env('GEMINI_MODEL', 'gemini-2.0-flash'),

    // Timeout for API requests (seconds)
    'timeout' => env('GEMINI_TIMEOUT', 30),

    // Maximum tokens in response
    'max_tokens' => env('GEMINI_MAX_TOKENS', 1024),

    // Temperature (0-1, lower = more deterministic)
    'temperature' => env('GEMINI_TEMPERATURE', 0.1),

    // PDF Editor Service URL (FastAPI)
    'pdf_editor_service_url' => env('PDF_EDITOR_SERVICE_URL', 'http://localhost:8001'),
];
