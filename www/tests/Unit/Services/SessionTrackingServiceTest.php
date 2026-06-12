<?php

namespace Tests\Unit\Services;

use App\Models\AnalyticsSession;
use App\Models\User;
use App\Services\SessionTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SessionTrackingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Clear session before each test
        session()->forget('analytics_session_id');
    }

    #[Test]
    public function it_creates_new_session_when_none_exists(): void
    {
        $this->assertNull(session('analytics_session_id'));

        $session = SessionTrackingService::getOrCreateSession();

        $this->assertInstanceOf(AnalyticsSession::class, $session);
        $this->assertTrue(Str::isUuid($session->id));
        $this->assertEquals($session->id, session('analytics_session_id'));
    }

    #[Test]
    public function it_returns_existing_session_when_valid(): void
    {
        // Create a session first
        $existingSession = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now(),
            'last_activity_at' => now()->subMinutes(5),
        ]);

        session(['analytics_session_id' => $existingSession->id]);

        $session = SessionTrackingService::getOrCreateSession();

        $this->assertEquals($existingSession->id, $session->id);
        // Verify last_activity_at was updated
        $this->assertTrue($session->last_activity_at->gt($existingSession->last_activity_at));
    }

    #[Test]
    public function it_creates_new_session_when_existing_is_ended(): void
    {
        // Create an ended session
        $endedSession = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now()->subHour(),
            'last_activity_at' => now()->subMinutes(30),
            'ended_at' => now()->subMinutes(30),
        ]);

        session(['analytics_session_id' => $endedSession->id]);

        $session = SessionTrackingService::getOrCreateSession();

        $this->assertNotEquals($endedSession->id, $session->id);
        $this->assertNull($session->ended_at);
    }

    #[Test]
    public function it_associates_user_id_with_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $session = SessionTrackingService::getOrCreateSession();

        $this->assertEquals($user->id, $session->user_id);
    }

    #[Test]
    public function it_uses_guest_sid_for_anonymous_users(): void
    {
        $guestSid = 'guest-'.Str::random(20);
        session(['guest_sid' => $guestSid]);

        $session = SessionTrackingService::getOrCreateSession();

        $this->assertNull($session->user_id);
        $this->assertEquals($guestSid, $session->guest_sid);
    }

    #[Test]
    public function it_detects_desktop_device(): void
    {
        // Use reflection to test the private detectDeviceType method directly
        $method = new \ReflectionMethod(SessionTrackingService::class, 'detectDeviceType');
        $method->setAccessible(true);

        // Mock request with desktop user agent
        $this->app->instance('request', \Illuminate\Http\Request::create('/', 'GET', [], [], [], [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ]));

        $result = $method->invoke(null);

        $this->assertEquals('desktop', $result);
    }

    #[Test]
    public function it_detects_mobile_device(): void
    {
        $method = new \ReflectionMethod(SessionTrackingService::class, 'detectDeviceType');
        $method->setAccessible(true);

        $this->app->instance('request', \Illuminate\Http\Request::create('/', 'GET', [], [], [], [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 14_0 like Mac OS X) AppleWebKit/605.1.15',
        ]));

        $result = $method->invoke(null);

        $this->assertEquals('mobile', $result);
    }

    #[Test]
    public function it_detects_tablet_device(): void
    {
        $method = new \ReflectionMethod(SessionTrackingService::class, 'detectDeviceType');
        $method->setAccessible(true);

        $this->app->instance('request', \Illuminate\Http\Request::create('/', 'GET', [], [], [], [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPad; CPU OS 14_0 like Mac OS X) AppleWebKit/605.1.15',
        ]));

        $result = $method->invoke(null);

        $this->assertEquals('tablet', $result);
    }

    #[Test]
    public function it_detects_bot(): void
    {
        $method = new \ReflectionMethod(SessionTrackingService::class, 'detectDeviceType');
        $method->setAccessible(true);

        $this->app->instance('request', \Illuminate\Http\Request::create('/', 'GET', [], [], [], [
            'HTTP_USER_AGENT' => 'Googlebot/2.1 (+http://www.google.com/bot.html)',
        ]));

        $result = $method->invoke(null);

        $this->assertEquals('bot', $result);
    }

    #[Test]
    public function it_stores_user_agent(): void
    {
        $userAgent = 'Mozilla/5.0 Test Browser';
        $this->app->instance('request', \Illuminate\Http\Request::create('/', 'GET', [], [], [], [
            'HTTP_USER_AGENT' => $userAgent,
        ]));

        $session = SessionTrackingService::getOrCreateSession();

        $this->assertEquals($userAgent, $session->user_agent);
    }

    #[Test]
    public function get_current_session_id_returns_null_when_no_session(): void
    {
        $sessionId = SessionTrackingService::getCurrentSessionId();

        $this->assertNull($sessionId);
    }

    #[Test]
    public function get_current_session_id_returns_id_when_session_exists(): void
    {
        $expectedId = Str::uuid()->toString();
        session(['analytics_session_id' => $expectedId]);

        $sessionId = SessionTrackingService::getCurrentSessionId();

        $this->assertEquals($expectedId, $sessionId);
    }

    #[Test]
    public function update_from_client_updates_rage_clicks(): void
    {
        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now(),
            'last_activity_at' => now(),
            'rage_clicks' => 2,
        ]);

        SessionTrackingService::updateFromClient($session->id, [
            'rage_clicks' => 5,
        ]);

        $session->refresh();
        $this->assertEquals(5, $session->rage_clicks);
    }

    #[Test]
    public function update_from_client_keeps_max_rage_clicks(): void
    {
        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now(),
            'last_activity_at' => now(),
            'rage_clicks' => 10,
        ]);

        // Lower value should not overwrite
        SessionTrackingService::updateFromClient($session->id, [
            'rage_clicks' => 5,
        ]);

        $session->refresh();
        $this->assertEquals(10, $session->rage_clicks);
    }

    #[Test]
    public function update_from_client_adds_rapid_click_count(): void
    {
        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now(),
            'last_activity_at' => now(),
            'rapid_click_count' => 5,
        ]);

        SessionTrackingService::updateFromClient($session->id, [
            'rapid_click_count' => 3,
        ]);

        $session->refresh();
        $this->assertEquals(8, $session->rapid_click_count);
    }

    #[Test]
    public function update_from_client_sets_form_abandonment(): void
    {
        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now(),
            'last_activity_at' => now(),
            'form_abandonment' => false,
        ]);

        SessionTrackingService::updateFromClient($session->id, [
            'form_abandonment' => true,
        ]);

        $session->refresh();
        $this->assertTrue($session->form_abandonment);
    }

    #[Test]
    public function update_from_client_updates_scroll_depth_with_max(): void
    {
        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now(),
            'last_activity_at' => now(),
            'scroll_depth' => 0.50,
        ]);

        SessionTrackingService::updateFromClient($session->id, [
            'scroll_depth' => 0.75,
        ]);

        $session->refresh();
        $this->assertEquals('0.75', $session->scroll_depth);

        // Lower value should not overwrite
        SessionTrackingService::updateFromClient($session->id, [
            'scroll_depth' => 0.30,
        ]);

        $session->refresh();
        $this->assertEquals('0.75', $session->scroll_depth);
    }

    #[Test]
    public function update_from_client_appends_session_actions(): void
    {
        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now(),
            'last_activity_at' => now(),
            'session_actions' => [
                ['type' => 'click', 'target' => '#btn1', 't' => 1],
            ],
        ]);

        SessionTrackingService::updateFromClient($session->id, [
            'actions' => [
                ['type' => 'click', 'target' => '#btn2', 't' => 2],
                ['type' => 'scroll', 'target' => null, 't' => 3],
            ],
        ]);

        $session->refresh();
        $this->assertCount(3, $session->session_actions);
    }

    #[Test]
    public function update_from_client_respects_max_session_actions_limit(): void
    {
        config(['analytics.max_session_actions' => 5]);

        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now(),
            'last_activity_at' => now(),
            'session_actions' => [
                ['type' => 'click', 't' => 1],
                ['type' => 'click', 't' => 2],
                ['type' => 'click', 't' => 3],
            ],
        ]);

        // Try to add 5 more actions (only 2 should be added)
        SessionTrackingService::updateFromClient($session->id, [
            'actions' => [
                ['type' => 'click', 't' => 4],
                ['type' => 'click', 't' => 5],
                ['type' => 'click', 't' => 6],
                ['type' => 'click', 't' => 7],
                ['type' => 'click', 't' => 8],
            ],
        ]);

        $session->refresh();
        $this->assertCount(5, $session->session_actions);
    }

    #[Test]
    public function update_from_client_ignores_actions_when_limit_reached(): void
    {
        config(['analytics.max_session_actions' => 3]);

        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now(),
            'last_activity_at' => now(),
            'session_actions' => [
                ['type' => 'click', 't' => 1],
                ['type' => 'click', 't' => 2],
                ['type' => 'click', 't' => 3],
            ],
        ]);

        // Try to add more when already at limit
        SessionTrackingService::updateFromClient($session->id, [
            'actions' => [
                ['type' => 'click', 't' => 4],
            ],
        ]);

        $session->refresh();
        $this->assertCount(3, $session->session_actions);
    }

    #[Test]
    public function update_from_client_stores_exit_actions_and_ends_session(): void
    {
        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $exitActions = [
            ['type' => 'click', 'target' => '#close', 't' => 100],
        ];

        SessionTrackingService::updateFromClient($session->id, [
            'exit_actions' => $exitActions,
        ]);

        $session->refresh();
        $this->assertEquals($exitActions, $session->last_actions_before_exit);
        $this->assertNotNull($session->ended_at);
    }

    #[Test]
    public function update_from_client_stores_session_group_id(): void
    {
        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $groupId = Str::uuid()->toString();

        SessionTrackingService::updateFromClient($session->id, [
            'session_group_id' => $groupId,
        ]);

        $session->refresh();
        $this->assertEquals($groupId, $session->session_group_id);
    }

    #[Test]
    public function update_from_client_does_nothing_for_invalid_session(): void
    {
        // Should not throw exception
        SessionTrackingService::updateFromClient('invalid-uuid', [
            'rage_clicks' => 5,
        ]);

        $this->assertTrue(true); // If we got here, it didn't crash
    }

    #[Test]
    public function update_from_client_updates_last_activity_at(): void
    {
        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now()->subHour(),
            'last_activity_at' => now()->subMinutes(30),
        ]);

        $originalActivityTime = $session->last_activity_at;

        SessionTrackingService::updateFromClient($session->id, [
            'rage_clicks' => 1,
        ]);

        $session->refresh();
        $this->assertTrue($session->last_activity_at->gt($originalActivityTime));
    }

    #[Test]
    public function end_session_marks_session_as_ended(): void
    {
        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        session(['analytics_session_id' => $session->id]);

        SessionTrackingService::endSession();

        $session->refresh();
        $this->assertNotNull($session->ended_at);
        $this->assertNull(session('analytics_session_id'));
    }

    #[Test]
    public function end_session_does_nothing_when_no_session(): void
    {
        session()->forget('analytics_session_id');

        // Should not throw exception
        SessionTrackingService::endSession();

        $this->assertTrue(true);
    }

    #[Test]
    public function end_session_does_not_re_end_already_ended_session(): void
    {
        $endedAt = now()->subMinutes(5);
        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now()->subHour(),
            'last_activity_at' => now()->subMinutes(30),
            'ended_at' => $endedAt,
        ]);

        session(['analytics_session_id' => $session->id]);

        SessionTrackingService::endSession();

        $session->refresh();
        // ended_at should still be the original value
        $this->assertEquals($endedAt->toDateTimeString(), $session->ended_at->toDateTimeString());
    }

    #[Test]
    public function it_detects_android_as_mobile(): void
    {
        $method = new \ReflectionMethod(SessionTrackingService::class, 'detectDeviceType');
        $method->setAccessible(true);

        $this->app->instance('request', \Illuminate\Http\Request::create('/', 'GET', [], [], [], [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Linux; Android 10; SM-G975F) AppleWebKit/537.36',
        ]));

        $result = $method->invoke(null);

        $this->assertEquals('mobile', $result);
    }

    #[Test]
    public function it_detects_crawler_bot(): void
    {
        $method = new \ReflectionMethod(SessionTrackingService::class, 'detectDeviceType');
        $method->setAccessible(true);

        $bots = [
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
            'Slurp/3.0',
            'YandexBot/3.0',
        ];

        foreach ($bots as $botAgent) {
            $this->app->instance('request', \Illuminate\Http\Request::create('/', 'GET', [], [], [], [
                'HTTP_USER_AGENT' => $botAgent,
            ]));

            $result = $method->invoke(null);

            $this->assertEquals('bot', $result, "Failed for: {$botAgent}");
        }
    }
}
