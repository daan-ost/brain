<?php

namespace Tests\Unit\Commands;

use App\Models\AnalyticsSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CleanupAnalyticsSessionsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_deletes_sessions_older_than_configured_days(): void
    {
        config(['analytics.cleanup.sessions_older_than_days' => 7]);

        // Create old session (should be deleted)
        $oldSession = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now()->subDays(10),
            'last_activity_at' => now()->subDays(10),
        ]);

        // Create recent session (should be kept)
        $recentSession = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now()->subDays(3),
            'last_activity_at' => now()->subDays(3),
        ]);

        $this->artisan('analytics:cleanup-sessions')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('analytics_sessions', ['id' => $oldSession->id]);
        $this->assertDatabaseHas('analytics_sessions', ['id' => $recentSession->id]);
    }

    #[Test]
    public function it_respects_custom_days_option(): void
    {
        // Create session 5 days old
        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now()->subDays(5),
            'last_activity_at' => now()->subDays(5),
        ]);

        // With default 7 days, it should be kept
        $this->artisan('analytics:cleanup-sessions --days=7')
            ->assertExitCode(0);

        $this->assertDatabaseHas('analytics_sessions', ['id' => $session->id]);

        // With 3 days, it should be deleted
        $this->artisan('analytics:cleanup-sessions --days=3')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('analytics_sessions', ['id' => $session->id]);
    }

    #[Test]
    public function dry_run_does_not_delete_sessions(): void
    {
        $oldSession = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now()->subDays(30),
            'last_activity_at' => now()->subDays(30),
        ]);

        $this->artisan('analytics:cleanup-sessions --dry-run')
            ->expectsOutput('DRY RUN - no data will be deleted')
            ->assertExitCode(0);

        $this->assertDatabaseHas('analytics_sessions', ['id' => $oldSession->id]);
    }

    #[Test]
    public function it_reports_when_no_sessions_to_cleanup(): void
    {
        // Create only recent session
        AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $this->artisan('analytics:cleanup-sessions')
            ->expectsOutput('No sessions to clean up')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_deletes_sessions_in_chunks(): void
    {
        // Create 5 old sessions
        for ($i = 0; $i < 5; $i++) {
            AnalyticsSession::create([
                'id' => Str::uuid()->toString(),
                'started_at' => now()->subDays(30),
                'last_activity_at' => now()->subDays(30),
            ]);
        }

        $this->artisan('analytics:cleanup-sessions')
            ->assertExitCode(0);

        $this->assertEquals(0, AnalyticsSession::count());
    }

    #[Test]
    public function dry_run_reports_count_of_sessions_to_delete(): void
    {
        // Create 3 old sessions
        for ($i = 0; $i < 3; $i++) {
            AnalyticsSession::create([
                'id' => Str::uuid()->toString(),
                'started_at' => now()->subDays(30),
                'last_activity_at' => now()->subDays(30),
            ]);
        }

        $this->artisan('analytics:cleanup-sessions --dry-run')
            ->expectsOutput('Would delete 3 sessions')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_uses_started_at_for_age_calculation(): void
    {
        // Session started long ago but had recent activity
        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now()->subDays(30), // Old start
            'last_activity_at' => now(), // Recent activity
        ]);

        $this->artisan('analytics:cleanup-sessions --days=7')
            ->assertExitCode(0);

        // Should be deleted based on started_at, not last_activity_at
        $this->assertDatabaseMissing('analytics_sessions', ['id' => $session->id]);
    }

    #[Test]
    public function it_preserves_sessions_at_boundary(): void
    {
        // Session exactly 7 days old (boundary)
        $boundarySession = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now()->subDays(7)->addSecond(),
            'last_activity_at' => now()->subDays(7),
        ]);

        $this->artisan('analytics:cleanup-sessions --days=7')
            ->assertExitCode(0);

        // Should be preserved (not older than 7 days)
        $this->assertDatabaseHas('analytics_sessions', ['id' => $boundarySession->id]);
    }
}
