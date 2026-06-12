<?php

namespace Tests\Unit\Services\AI;

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSession;
use App\Models\User;
use App\Services\AI\AnalyticsAggregatorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsAggregatorServiceTest extends TestCase
{
    use RefreshDatabase;

    private AnalyticsAggregatorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AnalyticsAggregatorService;
    }

    public function test_get_summary_returns_correct_structure(): void
    {
        $from = Carbon::parse('2025-01-01');
        $to = Carbon::parse('2025-01-15');

        $result = $this->service->getSummary($from, $to);

        $this->assertArrayHasKey('period', $result);
        $this->assertArrayHasKey('sessions', $result);
        $this->assertArrayHasKey('pages', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('performance', $result);

        // Verify period
        $this->assertEquals('2025-01-01', $result['period']['from']);
        $this->assertEquals('2025-01-15', $result['period']['to']);

        // Verify sessions structure
        $this->assertArrayHasKey('total', $result['sessions']);
        $this->assertArrayHasKey('unique_users', $result['sessions']);
        $this->assertArrayHasKey('avg_duration', $result['sessions']);
        $this->assertArrayHasKey('avg_scroll_depth', $result['sessions']);
        $this->assertArrayHasKey('actions_per_session', $result['sessions']);
        $this->assertArrayHasKey('rage_clicks', $result['sessions']);
        $this->assertArrayHasKey('rage_click_rate', $result['sessions']);
        $this->assertArrayHasKey('bounce_rate', $result['sessions']);
        $this->assertArrayHasKey('error_rate', $result['sessions']);
    }

    public function test_get_summary_throws_exception_for_range_over_30_days(): void
    {
        $from = Carbon::parse('2025-01-01');
        $to = Carbon::parse('2025-03-01');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Date range cannot exceed 30 days');

        $this->service->getSummary($from, $to);
    }

    public function test_get_summary_throws_exception_when_from_after_to(): void
    {
        $from = Carbon::parse('2025-01-15');
        $to = Carbon::parse('2025-01-01');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('From date must be before to date');

        $this->service->getSummary($from, $to);
    }

    public function test_get_summary_counts_sessions_correctly(): void
    {
        $user = User::factory()->create();
        $from = Carbon::parse('2025-01-01');
        $to = Carbon::parse('2025-01-15');

        // Create 3 sessions within range
        for ($i = 0; $i < 3; $i++) {
            AnalyticsSession::create([
                'user_id' => $user->id,
                'started_at' => $from->copy()->addDays($i),
                'last_activity_at' => $from->copy()->addDays($i)->addMinutes(5),
                'scroll_depth' => 0.5,
                'rage_clicks' => 2,
                'total_events' => 10,
                'total_pages_viewed' => 3,
            ]);
        }

        // Create 1 session outside range
        AnalyticsSession::create([
            'user_id' => $user->id,
            'started_at' => $to->copy()->addDays(5),
            'last_activity_at' => $to->copy()->addDays(5)->addMinutes(5),
        ]);

        $result = $this->service->getSummary($from, $to);

        $this->assertEquals(3, $result['sessions']['total']);
        $this->assertEquals(1, $result['sessions']['unique_users']);
        $this->assertEquals(6, $result['sessions']['rage_clicks']);
    }

    public function test_get_summary_calculates_scroll_depth_as_percentage(): void
    {
        $user = User::factory()->create();
        $from = Carbon::parse('2025-01-01');
        $to = Carbon::parse('2025-01-15');

        AnalyticsSession::create([
            'user_id' => $user->id,
            'started_at' => $from->copy()->addDay(),
            'last_activity_at' => $from->copy()->addDay()->addMinutes(5),
            'scroll_depth' => 0.75, // 75%
            'total_events' => 5,
            'total_pages_viewed' => 2,
        ]);

        $result = $this->service->getSummary($from, $to);

        $this->assertEquals(75, $result['sessions']['avg_scroll_depth']);
    }

    public function test_get_summary_counts_errors(): void
    {
        $user = User::factory()->create();
        $from = Carbon::parse('2025-01-01');
        $to = Carbon::parse('2025-01-15');

        // Create error events
        for ($i = 0; $i < 5; $i++) {
            AnalyticsEvent::create([
                'user_id' => $user->id,
                'event' => 'error',
                'error_code' => 'TypeError',
                'created_at' => $from->copy()->addDays($i),
            ]);
        }

        // Create non-error events
        for ($i = 0; $i < 10; $i++) {
            AnalyticsEvent::create([
                'user_id' => $user->id,
                'event' => 'page_view',
                'meta' => ['url' => '/test'],
                'created_at' => $from->copy()->addDays($i % 5),
            ]);
        }

        $result = $this->service->getSummary($from, $to);

        $this->assertEquals(5, $result['errors']['count']);
    }

    public function test_get_summary_returns_top_pages(): void
    {
        $user = User::factory()->create();
        $from = Carbon::parse('2025-01-01');
        $to = Carbon::parse('2025-01-15');

        // Create page view events
        for ($i = 0; $i < 10; $i++) {
            AnalyticsEvent::create([
                'user_id' => $user->id,
                'event' => 'page_view',
                'meta' => ['url' => '/upload'],
                'created_at' => $from->copy()->addDays($i % 5),
            ]);
        }

        for ($i = 0; $i < 5; $i++) {
            AnalyticsEvent::create([
                'user_id' => $user->id,
                'event' => 'page_view',
                'meta' => ['url' => '/convert'],
                'created_at' => $from->copy()->addDays($i),
            ]);
        }

        $result = $this->service->getSummary($from, $to);

        $this->assertNotEmpty($result['pages']['top_pages']);
        $this->assertEquals('/upload', $result['pages']['top_pages'][0]['url']);
        $this->assertEquals(10, $result['pages']['top_pages'][0]['views']);
    }

    public function test_get_user_diagnostics_returns_correct_structure(): void
    {
        $user = User::factory()->create();
        $from = Carbon::parse('2025-01-01');
        $to = Carbon::parse('2025-01-15');

        $result = $this->service->getUserDiagnostics($user->id, $from, $to);

        $this->assertArrayHasKey('user_id', $result);
        $this->assertArrayHasKey('period', $result);
        $this->assertArrayHasKey('recent_sessions', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('rage_clicks', $result);
        $this->assertArrayHasKey('page_flow', $result);

        $this->assertEquals($user->id, $result['user_id']);
    }

    public function test_get_user_diagnostics_returns_recent_sessions(): void
    {
        $user = User::factory()->create();
        $from = Carbon::parse('2025-01-01');
        $to = Carbon::parse('2025-01-15');

        // Create 5 sessions (should return only 3 most recent)
        for ($i = 0; $i < 5; $i++) {
            AnalyticsSession::create([
                'user_id' => $user->id,
                'started_at' => $from->copy()->addDays($i),
                'last_activity_at' => $from->copy()->addDays($i)->addMinutes(10),
                'ended_at' => $from->copy()->addDays($i)->addMinutes(10),
                'scroll_depth' => 0.5,
                'total_pages_viewed' => 3,
                'frustration_score' => 0.2,
            ]);
        }

        $result = $this->service->getUserDiagnostics($user->id, $from, $to);

        $this->assertCount(3, $result['recent_sessions']);
        // Most recent should be first
        $this->assertStringContainsString('2025-01-05', $result['recent_sessions'][0]['started_at']);
    }

    public function test_get_user_diagnostics_returns_user_errors(): void
    {
        $user = User::factory()->create();
        $from = Carbon::parse('2025-01-01');
        $to = Carbon::parse('2025-01-15');

        // Create errors for the user
        for ($i = 0; $i < 3; $i++) {
            AnalyticsEvent::create([
                'user_id' => $user->id,
                'event' => 'error',
                'error_code' => "Error$i",
                'meta' => ['url' => '/test'],
                'created_at' => $from->copy()->addDays($i),
            ]);
        }

        $result = $this->service->getUserDiagnostics($user->id, $from, $to);

        $this->assertCount(3, $result['errors']);
        $this->assertEquals('Error2', $result['errors'][0]['message']); // Most recent first
    }

    public function test_get_user_diagnostics_returns_rage_click_incidents(): void
    {
        $user = User::factory()->create();
        $from = Carbon::parse('2025-01-01');
        $to = Carbon::parse('2025-01-15');

        // Create sessions with rage clicks
        AnalyticsSession::create([
            'user_id' => $user->id,
            'started_at' => $from->copy()->addDay(),
            'last_activity_at' => $from->copy()->addDay()->addMinutes(5),
            'rage_clicks' => 5,
            'frustration_score' => 0.8,
        ]);

        AnalyticsSession::create([
            'user_id' => $user->id,
            'started_at' => $from->copy()->addDays(2),
            'last_activity_at' => $from->copy()->addDays(2)->addMinutes(5),
            'rage_clicks' => 0,
            'frustration_score' => 0.1,
        ]);

        $result = $this->service->getUserDiagnostics($user->id, $from, $to);

        $this->assertCount(1, $result['rage_clicks']); // Only session with rage_clicks > 0
        $this->assertEquals(5, $result['rage_clicks'][0]['rage_clicks']);
    }

    public function test_get_user_diagnostics_only_returns_data_for_specified_user(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $from = Carbon::parse('2025-01-01');
        $to = Carbon::parse('2025-01-15');

        // Create session for user1
        AnalyticsSession::create([
            'user_id' => $user1->id,
            'started_at' => $from->copy()->addDay(),
            'last_activity_at' => $from->copy()->addDay()->addMinutes(5),
            'scroll_depth' => 0.5,
        ]);

        // Create session for user2
        AnalyticsSession::create([
            'user_id' => $user2->id,
            'started_at' => $from->copy()->addDay(),
            'last_activity_at' => $from->copy()->addDay()->addMinutes(5),
            'scroll_depth' => 0.7,
        ]);

        $result = $this->service->getUserDiagnostics($user1->id, $from, $to);

        $this->assertCount(1, $result['recent_sessions']);
    }

    public function test_get_summary_handles_empty_data_gracefully(): void
    {
        $from = Carbon::parse('2025-01-01');
        $to = Carbon::parse('2025-01-15');

        $result = $this->service->getSummary($from, $to);

        $this->assertEquals(0, $result['sessions']['total']);
        $this->assertEquals(0, $result['sessions']['unique_users']);
        $this->assertEquals(0, $result['sessions']['rage_clicks']);
        $this->assertEquals(0, $result['errors']['count']);
        $this->assertEmpty($result['pages']['top_pages']);
    }
}
