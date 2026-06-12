<?php

return [
    /*
    |--------------------------------------------------------------------------
    | MCP (Model Context Protocol) Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for MCP server connections and tool integrations
    | used to enhance the PDF conversion system with external capabilities.
    |
    */

    'enabled' => env('MCP_ENABLED', true),

    'servers' => [
        'playwright' => [
            'enabled' => env('MCP_PLAYWRIGHT_ENABLED', true),
            'command' => env('MCP_PLAYWRIGHT_COMMAND', 'npx @modelcontextprotocol/server-playwright'),
            'args' => [],
            'timeout' => env('MCP_PLAYWRIGHT_TIMEOUT', 30000), // 30 seconds
            'tools' => [
                'playwright_screenshot',
                'playwright_pdf_from_html',
                'playwright_navigate',
                'playwright_extract_text',
                'playwright_fill_form',
                'playwright_click',
                'playwright_get_page_content',
            ],
        ],

        'filesystem' => [
            'enabled' => env('MCP_FILESYSTEM_ENABLED', true),
            'command' => env('MCP_FILESYSTEM_COMMAND', 'npx @modelcontextprotocol/server-filesystem'),
            'args' => [storage_path()], // Allow access to Laravel storage
            'timeout' => env('MCP_FILESYSTEM_TIMEOUT', 10000),
            'tools' => [
                'read_file',
                'write_file',
                'create_directory',
                'list_directory',
                'move_file',
                'search_files',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP Integration Settings
    |--------------------------------------------------------------------------
    */

    'integration' => [
        // Use Playwright for HTML to PDF conversions
        'html_to_pdf_via_playwright' => env('MCP_HTML_PDF_ENABLED', true),

        // Use Playwright for web scraping and content extraction
        'web_content_extraction' => env('MCP_WEB_EXTRACTION_ENABLED', true),

        // Use Playwright for automated testing
        'workflow_testing' => env('MCP_WORKFLOW_TESTING_ENABLED', true),

        // Use filesystem MCP for enhanced file operations
        'enhanced_file_ops' => env('MCP_ENHANCED_FILE_OPS_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Workflow Enhancement Settings
    |--------------------------------------------------------------------------
    */

    'workflow_enhancements' => [
        // Add web-based conversion steps
        'web_to_pdf' => [
            'max_pages' => 100,
            'timeout_per_page' => 30000,
            'viewport' => ['width' => 1280, 'height' => 720],
            'wait_for_load' => true,
        ],

        // Screenshot-based conversions
        'screenshot_to_pdf' => [
            'full_page' => true,
            'quality' => 90,
            'format' => 'png',
        ],

        // Form automation for document generation
        'form_automation' => [
            'enabled' => true,
            'max_form_fields' => 50,
            'timeout_per_action' => 5000,
        ],
    ],
];
