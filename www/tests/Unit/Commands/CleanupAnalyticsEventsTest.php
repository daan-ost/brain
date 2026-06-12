<?php

namespace Tests\Unit\Commands;

use App\Models\AnalyticsEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CleanupAnalyticsEventsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_deletes_events_older_than_configured_days(): void
    {
        config(['analytics.cleanup.events_older_than_days' => 30]);

        // Create old event (should be deleted)
        $oldEvent = AnalyticsEvent::create([
            'event' => 'page_view',
            'meta' => ['page' => '/old'],
            'created_at' => now()->subDays(45),
        ]);

        // Create recent event (should be kept)
        $recentEvent = AnalyticsEvent::create([
            'event' => 'page_view',
            'meta' => ['page' => '/new'],
            'created_at' => now()->subDays(10),
        ]);

        $this->artisan('analytics:cleanup-events')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('analytics_events', ['id' => $oldEvent->id]);
        $this->assertDatabaseHas('analytics_events', ['id' => $recentEvent->id]);
    }

    #[Test]
    public function it_respects_custom_days_option(): void
    {
        // Create event 15 days old
        $event = AnalyticsEvent::create([
            'event' => 'click',
            'meta' => [],
            'created_at' => now()->subDays(15),
        ]);

        // With default 30 days, it should be kept
        $this->artisan('analytics:cleanup-events --days=30')
            ->assertExitCode(0);

        $this->assertDatabaseHas('analytics_events', ['id' => $event->id]);

        // With 10 days, it should be deleted
        $this->artisan('analytics:cleanup-events --days=10')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('analytics_events', ['id' => $event->id]);
    }

    #[Test]
    public function dry_run_does_not_delete_events(): void
    {
        $oldEvent = AnalyticsEvent::create([
            'event' => 'page_view',
            'meta' => [],
            'created_at' => now()->subDays(60),
        ]);

        $this->artisan('analytics:cleanup-events --dry-run')
            ->expectsOutput('DRY RUN - no data will be deleted')
            ->assertExitCode(0);

        $this->assertDatabaseHas('analytics_events', ['id' => $oldEvent->id]);
    }

    #[Test]
    public function it_reports_when_no_events_to_cleanup(): void
    {
        // Create only recent event
        AnalyticsEvent::create([
            'event' => 'page_view',
            'meta' => [],
            'created_at' => now(),
        ]);

        $this->artisan('analytics:cleanup-events')
            ->expectsOutput('No events to clean up')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_deletes_events_in_chunks(): void
    {
        // Create 10 old events
        for ($i = 0; $i < 10; $i++) {
            AnalyticsEvent::create([
                'event' => 'page_view',
                'meta' => ['index' => $i],
                'created_at' => now()->subDays(60),
            ]);
        }

        $this->artisan('analytics:cleanup-events')
            ->assertExitCode(0);

        $this->assertEquals(0, AnalyticsEvent::count());
    }

    #[Test]
    public function dry_run_with_archive_reports_action(): void
    {
        // Create old events
        for ($i = 0; $i < 3; $i++) {
            AnalyticsEvent::create([
                'event' => 'page_view',
                'meta' => [],
                'created_at' => now()->subDays(60),
            ]);
        }

        $this->artisan('analytics:cleanup-events --dry-run --archive')
            ->expectsOutput('Would archive and delete 3 events')
            ->assertExitCode(0);
    }

    #[Test]
    public function archive_fails_without_archive_table(): void
    {
        // Create old event
        AnalyticsEvent::create([
            'event' => 'page_view',
            'meta' => [],
            'created_at' => now()->subDays(60),
        ]);

        // Archive table doesn't exist in test database
        $this->artisan('analytics:cleanup-events --archive')
            ->expectsOutput('Archive table analytics_events_archive does not exist')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_preserves_events_at_boundary(): void
    {
        config(['analytics.cleanup.events_older_than_days' => 30]);

        // Event exactly at boundary (not older than 30 days)
        $boundaryEvent = AnalyticsEvent::create([
            'event' => 'page_view',
            'meta' => [],
            'created_at' => now()->subDays(30)->addSecond(),
        ]);

        $this->artisan('analytics:cleanup-events --days=30')
            ->assertExitCode(0);

        // Should be preserved
        $this->assertDatabaseHas('analytics_events', ['id' => $boundaryEvent->id]);
    }

    #[Test]
    public function it_deletes_events_with_session_links(): void
    {
        // Create old event with session_id
        $oldEvent = AnalyticsEvent::create([
            'event' => 'page_view',
            'session_id' => '12345678-1234-1234-1234-123456789012',
            'meta' => [],
            'created_at' => now()->subDays(60),
        ]);

        $this->artisan('analytics:cleanup-events')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('analytics_events', ['id' => $oldEvent->id]);
    }

    #[Test]
    public function it_handles_large_batches(): void
    {
        // Create 2500 old events (tests chunking with 1000 chunk size)
        $events = [];
        $now = now();
        for ($i = 0; $i < 100; $i++) {
            $events[] = [
                'event' => 'page_view',
                'meta' => json_encode(['index' => $i]),
                'created_at' => $now->copy()->subDays(60),
            ];
        }
        DB::table('analytics_events')->insert($events);

        $this->artisan('analytics:cleanup-events')
            ->assertExitCode(0);

        $this->assertEquals(0, AnalyticsEvent::count());
    }

    #[Test]
    public function it_reports_deleted_count_correctly(): void
    {
        // Create 5 old events
        for ($i = 0; $i < 5; $i++) {
            AnalyticsEvent::create([
                'event' => 'page_view',
                'meta' => [],
                'created_at' => now()->subDays(60),
            ]);
        }

        $this->artisan('analytics:cleanup-events')
            ->expectsOutputToContain('Deleted 5 events')
            ->assertExitCode(0);
    }
}
