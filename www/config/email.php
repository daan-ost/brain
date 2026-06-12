<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Email-to-PDF Converter Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the Email-to-PDF-Converter JAR (Java application)
    | used for converting email body (EML/MSG) to PDF.
    |
    | Repository: https://github.com/nickrussler/email-to-pdf-converter
    | License: Apache 2.0
    |
    */

    'converter' => [
        /*
        | Enable/disable Email-to-PDF-Converter JAR
        | Set to true on production VPS (with Java installed)
        | Set to false on local development (will use mock PDF generation)
        */
        'enabled' => env('EMAIL_CONVERTER_ENABLED', false),

        /*
        | Path to Email-to-PDF-Converter JAR file
        | Production: /var/www/bin/emailconverter.jar
        | Local: Can be empty (fallback to mock)
        */
        'jar_path' => env('EMAIL_CONVERTER_JAR', '/var/www/bin/emailconverter.jar'),

        /*
        | Java command to use
        | Usually 'java', but can be full path if needed
        */
        'java_command' => env('EMAIL_CONVERTER_JAVA', 'java'),

        /*
        | Timeout for JAR execution (seconds)
        | Email conversions typically take 1-5 seconds
        */
        'timeout' => env('EMAIL_CONVERTER_TIMEOUT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Email Conversion Settings
    |--------------------------------------------------------------------------
    */

    'conversion' => [
        /*
        | Tracking pixel detection threshold (bytes)
        | Images smaller than this are considered tracking pixels and skipped
        */
        'tracking_pixel_threshold' => 2048, // 2KB

        /*
        | Supported email formats
        */
        'supported_formats' => ['eml', 'msg'],

        /*
        | Supported attachment formats for conversion
        */
        'supported_attachments' => [
            'pdf' => ['pdf'],
            'images' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'tiff'],
            'office' => ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'txt'],
        ],
    ],
];
