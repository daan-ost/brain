<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * MCP (Model Context Protocol) Client Service
 * Handles communication with MCP servers for enhanced document processing
 */
class McpClientService
{
    private array $activeConnections = [];

    private array $serverConfigs;

    public function __construct()
    {
        $this->serverConfigs = config('mcp.servers', []);
    }

    /**
     * Execute a Playwright MCP tool for web-based operations
     */
    public function executePlaywrightTool(string $tool, array $arguments = []): array
    {
        if (! $this->isServerEnabled('playwright')) {
            throw new \Exception('Playwright MCP server is not enabled');
        }

        return $this->executeTool('playwright', $tool, $arguments);
    }

    /**
     * Generate PDF from HTML using Playwright
     */
    public function htmlToPdf(string $html, array $options = []): string
    {
        $defaultOptions = [
            'format' => 'A4',
            'margin' => ['top' => '1cm', 'bottom' => '1cm', 'left' => '1cm', 'right' => '1cm'],
            'printBackground' => true,
            'preferCSSPageSize' => false,
        ];

        $pdfOptions = array_merge($defaultOptions, $options);

        Log::info('McpClientService: Generating PDF from HTML using Playwright', [
            'html_length' => strlen($html),
            'options' => $pdfOptions,
        ]);

        $result = $this->executePlaywrightTool('playwright_pdf_from_html', [
            'html' => $html,
            'options' => $pdfOptions,
        ]);

        if (! isset($result['content'])) {
            throw new \Exception('Failed to generate PDF from HTML via Playwright MCP');
        }

        // Save the PDF to a temporary file and return the path
        $tempPath = sys_get_temp_dir().'/'.uniqid('mcp_html_pdf_').'.pdf';

        if (isset($result['content']['base64'])) {
            file_put_contents($tempPath, base64_decode($result['content']['base64']));
        } elseif (isset($result['content']['binary'])) {
            file_put_contents($tempPath, $result['content']['binary']);
        } else {
            throw new \Exception('Invalid PDF content format from Playwright MCP');
        }

        return $tempPath;
    }

    /**
     * Take screenshot of a web page using Playwright
     */
    public function takeScreenshot(string $url, array $options = []): string
    {
        $defaultOptions = [
            'fullPage' => true,
            'quality' => 90,
            'type' => 'png',
            'viewport' => ['width' => 1280, 'height' => 720],
        ];

        $screenshotOptions = array_merge($defaultOptions, $options);

        Log::info('McpClientService: Taking screenshot using Playwright', [
            'url' => $url,
            'options' => $screenshotOptions,
        ]);

        // Navigate to the page first
        $this->executePlaywrightTool('playwright_navigate', ['url' => $url]);

        // Take the screenshot
        $result = $this->executePlaywrightTool('playwright_screenshot', $screenshotOptions);

        if (! isset($result['content'])) {
            throw new \Exception('Failed to take screenshot via Playwright MCP');
        }

        // Save screenshot to temporary file
        $extension = $screenshotOptions['type'] === 'jpeg' ? 'jpg' : 'png';
        $tempPath = sys_get_temp_dir().'/'.uniqid('mcp_screenshot_').'.'.$extension;

        if (isset($result['content']['base64'])) {
            file_put_contents($tempPath, base64_decode($result['content']['base64']));
        } else {
            file_put_contents($tempPath, $result['content']);
        }

        return $tempPath;
    }

    /**
     * Extract text content from a web page
     */
    public function extractWebText(string $url): string
    {
        Log::info('McpClientService: Extracting text content from web page', ['url' => $url]);

        // Navigate to the page
        $this->executePlaywrightTool('playwright_navigate', ['url' => $url]);

        // Extract text content
        $result = $this->executePlaywrightTool('playwright_extract_text', []);

        return $result['content'] ?? '';
    }

    /**
     * Convert a web page to PDF
     */
    public function webPageToPdf(string $url, array $options = []): string
    {
        $defaultOptions = [
            'format' => 'A4',
            'margin' => ['top' => '1cm', 'bottom' => '1cm', 'left' => '1cm', 'right' => '1cm'],
            'printBackground' => true,
            'waitUntil' => 'networkidle',
        ];

        $pdfOptions = array_merge($defaultOptions, $options);

        Log::info('McpClientService: Converting web page to PDF using Playwright', [
            'url' => $url,
            'options' => $pdfOptions,
        ]);

        // Navigate to the page
        $this->executePlaywrightTool('playwright_navigate', [
            'url' => $url,
            'waitUntil' => $pdfOptions['waitUntil'] ?? 'networkidle',
        ]);

        // Generate PDF
        $result = $this->executePlaywrightTool('playwright_pdf_from_page', $pdfOptions);

        if (! isset($result['content'])) {
            throw new \Exception('Failed to convert web page to PDF via Playwright MCP');
        }

        // Save PDF to temporary file
        $tempPath = sys_get_temp_dir().'/'.uniqid('mcp_webpage_pdf_').'.pdf';
        file_put_contents($tempPath, base64_decode($result['content']['base64']));

        return $tempPath;
    }

    /**
     * Execute filesystem operations using MCP
     */
    public function executeFileOperation(string $operation, array $arguments = []): array
    {
        if (! $this->isServerEnabled('filesystem')) {
            throw new \Exception('Filesystem MCP server is not enabled');
        }

        return $this->executeTool('filesystem', $operation, $arguments);
    }

    /**
     * Enhanced file search using MCP filesystem tools
     */
    public function searchFiles(string $pattern, ?string $directory = null): array
    {
        $searchDir = $directory ?? storage_path();

        Log::info('McpClientService: Searching files using MCP', [
            'pattern' => $pattern,
            'directory' => $searchDir,
        ]);

        $result = $this->executeFileOperation('search_files', [
            'pattern' => $pattern,
            'directory' => $searchDir,
        ]);

        return $result['matches'] ?? [];
    }

    /**
     * Generic tool execution method
     */
    private function executeTool(string $serverName, string $tool, array $arguments = []): array
    {
        if (! isset($this->serverConfigs[$serverName])) {
            throw new \Exception("MCP server '{$serverName}' is not configured");
        }

        $serverConfig = $this->serverConfigs[$serverName];

        if (! in_array($tool, $serverConfig['tools'])) {
            throw new \Exception("Tool '{$tool}' is not available on MCP server '{$serverName}'");
        }

        // For now, this is a mock implementation
        // In a real implementation, you would establish a proper MCP connection
        Log::info('McpClientService: Executing MCP tool', [
            'server' => $serverName,
            'tool' => $tool,
            'arguments' => $arguments,
        ]);

        // Mock response for development/testing
        return $this->mockToolResponse($tool, $arguments);
    }

    /**
     * Mock tool responses for development (replace with real MCP communication)
     */
    private function mockToolResponse(string $tool, array $arguments): array
    {
        switch ($tool) {
            case 'playwright_pdf_from_html':
                return [
                    'success' => true,
                    'content' => ['base64' => base64_encode('Mock PDF content')],
                    'metadata' => ['pages' => 1, 'size' => 1024],
                ];

            case 'playwright_screenshot':
                return [
                    'success' => true,
                    'content' => ['base64' => base64_encode('Mock PNG content')],
                    'metadata' => ['width' => 1280, 'height' => 720],
                ];

            case 'playwright_extract_text':
                return [
                    'success' => true,
                    'content' => 'Mock extracted text content from web page',
                    'metadata' => ['length' => 42],
                ];

            case 'search_files':
                return [
                    'success' => true,
                    'matches' => [
                        '/path/to/file1.pdf',
                        '/path/to/file2.docx',
                    ],
                ];

            default:
                return [
                    'success' => true,
                    'content' => "Mock response for tool: {$tool}",
                    'arguments' => $arguments,
                ];
        }
    }

    /**
     * Check if an MCP server is enabled
     */
    private function isServerEnabled(string $serverName): bool
    {
        return config('mcp.enabled', true) &&
               isset($this->serverConfigs[$serverName]) &&
               $this->serverConfigs[$serverName]['enabled'];
    }

    /**
     * Get available tools for a server
     */
    public function getAvailableTools(string $serverName): array
    {
        if (! isset($this->serverConfigs[$serverName])) {
            return [];
        }

        return $this->serverConfigs[$serverName]['tools'] ?? [];
    }

    /**
     * Test MCP server connectivity
     */
    public function testConnection(string $serverName): array
    {
        try {
            $result = $this->executeTool($serverName, 'ping', []);

            return [
                'status' => 'connected',
                'server' => $serverName,
                'response' => $result,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'server' => $serverName,
                'error' => $e->getMessage(),
            ];
        }
    }
}
