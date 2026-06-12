<?php

namespace App\Console\Commands;

use App\Services\McpClientService;
use App\Services\WorkflowValidator;
use Illuminate\Console\Command;

class TestMcpIntegration extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'mcp:test {--service=all : Test specific MCP service (playwright|filesystem|all)}';

    /**
     * The console command description.
     */
    protected $description = 'Test MCP (Model Context Protocol) integration with the PDF conversion system';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $service = $this->option('service');

        $this->info('Testing MCP Integration for PDF Conversion System');
        $this->info('================================================');

        // Test configuration loading
        $this->testConfiguration();

        // Test MCP service instantiation
        $this->testServiceInstantiation();

        // Test specific services
        if ($service === 'all' || $service === 'playwright') {
            $this->testPlaywrightIntegration();
        }

        if ($service === 'all' || $service === 'filesystem') {
            $this->testFilesystemIntegration();
        }

        // Test workflow integration
        $this->testWorkflowIntegration();

        $this->info('MCP Integration tests completed!');
    }

    /**
     * Test MCP configuration loading
     */
    private function testConfiguration()
    {
        $this->info('Testing MCP Configuration...');

        $mcpEnabled = config('mcp.enabled');
        $playwrightEnabled = config('mcp.servers.playwright.enabled');
        $filesystemEnabled = config('mcp.servers.filesystem.enabled');

        $this->line('  MCP Enabled: '.($mcpEnabled ? '✅ YES' : '❌ NO'));
        $this->line('  Playwright Server: '.($playwrightEnabled ? '✅ ENABLED' : '❌ DISABLED'));
        $this->line('  Filesystem Server: '.($filesystemEnabled ? '✅ ENABLED' : '❌ DISABLED'));

        // Test workflow steps with MCP
        $steps = config('workflow_steps.steps');
        $mcpSteps = array_filter($steps, fn ($step) => isset($step['mcp_enabled']) && $step['mcp_enabled']);

        $this->line('  MCP-Enhanced Steps: '.count($mcpSteps));
        foreach ($mcpSteps as $stepName => $step) {
            $this->line("    - {$stepName}: {$step['name']}");
        }

        $this->newLine();
    }

    /**
     * Test MCP service instantiation
     */
    private function testServiceInstantiation()
    {
        $this->info('Testing MCP Service Instantiation...');

        try {
            $mcpService = app(McpClientService::class);
            $this->line('  McpClientService: ✅ INSTANTIATED');

            // Test available tools
            $playwrightTools = $mcpService->getAvailableTools('playwright');
            $filesystemTools = $mcpService->getAvailableTools('filesystem');

            $this->line('  Playwright Tools: '.count($playwrightTools));
            $this->line('  Filesystem Tools: '.count($filesystemTools));

        } catch (\Exception $e) {
            $this->line('  McpClientService: ❌ FAILED - '.$e->getMessage());
        }

        $this->newLine();
    }

    /**
     * Test Playwright MCP integration
     */
    private function testPlaywrightIntegration()
    {
        $this->info('Testing Playwright MCP Integration...');

        try {
            $mcpService = app(McpClientService::class);

            // Test HTML to PDF conversion
            $testHtml = '<html><body><h1>Test HTML to PDF</h1><p>This is a test document.</p></body></html>';

            $this->line('  Testing HTML to PDF conversion...');
            $pdfPath = $mcpService->htmlToPdf($testHtml);

            if (file_exists($pdfPath)) {
                $fileSize = filesize($pdfPath);
                $this->line("  ✅ HTML to PDF: SUCCESS (Generated {$fileSize} bytes)");
                @unlink($pdfPath); // Clean up
            } else {
                $this->line('  ❌ HTML to PDF: FAILED (No file generated)');
            }

            // Test screenshot functionality
            $this->line('  Testing screenshot functionality...');
            $screenshotPath = $mcpService->takeScreenshot('https://example.com');

            if (file_exists($screenshotPath)) {
                $fileSize = filesize($screenshotPath);
                $this->line("  ✅ Screenshot: SUCCESS (Generated {$fileSize} bytes)");
                @unlink($screenshotPath); // Clean up
            } else {
                $this->line('  ❌ Screenshot: FAILED (No file generated)');
            }

        } catch (\Exception $e) {
            $this->line('  ❌ Playwright Integration: FAILED - '.$e->getMessage());
        }

        $this->newLine();
    }

    /**
     * Test Filesystem MCP integration
     */
    private function testFilesystemIntegration()
    {
        $this->info('Testing Filesystem MCP Integration...');

        try {
            $mcpService = app(McpClientService::class);

            // Test file search
            $this->line('  Testing file search functionality...');
            $searchResults = $mcpService->searchFiles('*.pdf', storage_path('app'));

            $this->line('  ✅ File Search: SUCCESS (Found '.count($searchResults).' results)');

            // Test connection
            $connectionTest = $mcpService->testConnection('filesystem');
            $status = $connectionTest['status'] === 'connected' ? '✅ CONNECTED' : '❌ FAILED';
            $this->line("  Connection Test: {$status}");

        } catch (\Exception $e) {
            $this->line('  ❌ Filesystem Integration: FAILED - '.$e->getMessage());
        }

        $this->newLine();
    }

    /**
     * Test workflow integration with MCP steps
     */
    private function testWorkflowIntegration()
    {
        $this->info('Testing Workflow Integration...');

        try {
            $validator = app(WorkflowValidator::class);

            // Test MCP-enhanced workflow validation
            $mcpWorkflow = [
                ['type' => 'html_to_pdf', 'options' => []],
                ['type' => 'compress_pdf', 'options' => []],
                ['type' => 'pdf_to_word', 'options' => []],
            ];

            $result = $validator->validateWorkflow($mcpWorkflow);

            if ($result['is_valid']) {
                $this->line('  ✅ MCP Workflow Validation: SUCCESS');
                $this->line('    HTML → PDF → Compress → Word conversion chain validated');
            } else {
                $this->line('  ❌ MCP Workflow Validation: FAILED');
                foreach ($result['errors'] as $error) {
                    $this->line("    - {$error}");
                }
            }

            // Test web conversion workflow
            $webWorkflow = [
                ['type' => 'web_to_pdf', 'options' => []],
                ['type' => 'pdf_to_images', 'options' => []],
            ];

            $webResult = $validator->validateWorkflow($webWorkflow);

            if ($webResult['is_valid']) {
                $this->line('  ✅ Web Conversion Workflow: SUCCESS');
                $this->line('    Web → PDF → Images conversion chain validated');
            } else {
                $this->line('  ❌ Web Conversion Workflow: FAILED');
            }

        } catch (\Exception $e) {
            $this->line('  ❌ Workflow Integration: FAILED - '.$e->getMessage());
        }

        $this->newLine();
    }
}
