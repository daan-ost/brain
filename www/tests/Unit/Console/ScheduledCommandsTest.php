<?php

namespace Tests\Unit\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Tests to verify all scheduled commands are properly registered.
 *
 * These tests prevent the Laravel 11+ scheduler issue where commands
 * defined in Kernel.php's schedule() method are silently ignored.
 * All scheduled commands MUST be defined in routes/console.php.
 */
class ScheduledCommandsTest extends TestCase
{
    private Schedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schedule = app(Schedule::class);
    }

    /**
     * Get all scheduled command signatures.
     */
    private function getScheduledCommands(): array
    {
        return collect($this->schedule->events())
            ->map(fn ($event) => $event->command)
            ->map(fn ($cmd) => preg_replace("/^'[^']+' 'artisan' /", '', $cmd))
            ->map(fn ($cmd) => trim(str_replace("'", '', $cmd)))
            ->toArray();
    }

    // ==================== BUSINESS LOGIC COMMANDS ====================

    public function test_license_process_credits_is_scheduled(): void
    {
        $commands = $this->getScheduledCommands();
        $this->assertContains('license:process-credits', $commands);
    }

    public function test_license_send_notifications_is_scheduled(): void
    {
        $commands = $this->getScheduledCommands();
        $this->assertContains('license:send-notifications', $commands);
    }

    public function test_license_process_invoice_renewals_is_scheduled(): void
    {
        $commands = $this->getScheduledCommands();
        $this->assertContains('license:process-invoice-renewals', $commands);
    }

    public function test_messages_send_unread_notifications_is_scheduled(): void
    {
        $commands = $this->getScheduledCommands();
        $this->assertContains('messages:send-unread-notifications', $commands);
    }

    public function test_license_process_price_changes_is_scheduled(): void
    {
        $commands = $this->getScheduledCommands();
        $this->assertTrue(
            collect($commands)->contains(fn ($cmd) => str_contains($cmd, 'license:process-price-changes')),
            'license:process-price-changes should be scheduled'
        );
    }

    // ==================== FILE CLEANUP COMMANDS ====================

    public function test_cleanup_uploads_is_scheduled(): void
    {
        $commands = $this->getScheduledCommands();
        $this->assertContains('cleanup:uploads', $commands);
    }

    public function test_cleanup_workflow_temp_is_scheduled(): void
    {
        $commands = $this->getScheduledCommands();
        $this->assertContains('cleanup:workflow-temp', $commands);
    }

    public function test_cleanup_temp_is_scheduled(): void
    {
        $commands = $this->getScheduledCommands();
        $this->assertContains('cleanup:temp', $commands);
    }

    public function test_cleanup_expired_batches_is_scheduled(): void
    {
        $commands = $this->getScheduledCommands();
        $this->assertContains('cleanup:expired-batches', $commands);
    }

    public function test_cleanup_conversions_is_scheduled(): void
    {
        $commands = $this->getScheduledCommands();
        $this->assertContains('cleanup:conversions', $commands);
    }

    public function test_cleanup_failed_conversions_is_scheduled(): void
    {
        $commands = $this->getScheduledCommands();
        $this->assertContains('cleanup:failed-conversions', $commands);
    }

    public function test_cleanup_api_sessions_is_scheduled(): void
    {
        $commands = $this->getScheduledCommands();
        $this->assertContains('cleanup:api-sessions', $commands);
    }

    public function test_inbound_cleanup_is_scheduled(): void
    {
        $commands = $this->getScheduledCommands();
        $this->assertContains('inbound:cleanup', $commands);
    }

    // ==================== DATABASE CLEANUP COMMANDS ====================

    public function test_email_cleanup_expired_changes_is_scheduled(): void
    {
        $commands = $this->getScheduledCommands();
        $this->assertContains('email:cleanup-expired-changes', $commands);
    }

    public function test_cleanup_expired_invitations_is_scheduled(): void
    {
        $commands = $this->getScheduledCommands();
        $this->assertContains('cleanup:expired-invitations', $commands);
    }

    public function test_cleanup_orphaned_file_keys_is_scheduled(): void
    {
        $commands = $this->getScheduledCommands();
        $this->assertContains('cleanup:orphaned-file-keys', $commands);
    }

    public function test_analytics_cleanup_sessions_is_scheduled(): void
    {
        $commands = $this->getScheduledCommands();
        $this->assertContains('analytics:cleanup-sessions', $commands);
    }

    public function test_analytics_cleanup_events_is_scheduled(): void
    {
        $commands = $this->getScheduledCommands();
        $this->assertContains('analytics:cleanup-events', $commands);
    }

    // ==================== SCHEDULE LIST VERIFICATION ====================

    public function test_schedule_list_shows_all_commands(): void
    {
        $this->artisan('schedule:list')
            ->assertSuccessful()
            ->expectsOutputToContain('license:process-credits')
            ->expectsOutputToContain('cleanup:uploads')
            ->expectsOutputToContain('cleanup:conversions')
            ->expectsOutputToContain('analytics:cleanup-sessions');
    }

    public function test_kernel_schedule_method_is_empty(): void
    {
        $kernel = app(\App\Console\Kernel::class);
        $schedule = new Schedule;

        $reflection = new \ReflectionClass($kernel);
        $method = $reflection->getMethod('schedule');
        $method->setAccessible(true);
        $method->invoke($kernel, $schedule);

        // The Kernel schedule method should be empty - all commands should be in routes/console.php
        $this->assertEmpty(
            $schedule->events(),
            'Kernel::schedule() should be empty. All scheduled commands must be in routes/console.php for Laravel 11+'
        );
    }

    // ==================== MINIMUM COMMAND COUNT ====================

    public function test_minimum_scheduled_commands_count(): void
    {
        $commands = $this->getScheduledCommands();

        // We expect at least 18 scheduled commands
        // This catches accidental removal of scheduled commands
        $this->assertGreaterThanOrEqual(
            18,
            count($commands),
            'Expected at least 18 scheduled commands. Some commands may have been accidentally removed.'
        );
    }
}
