<?php

namespace Tests\Unit\Models;

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AnalyticsSessionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_session_with_uuid_primary_key(): void
    {
        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $this->assertNotNull($session->id);
        $this->assertTrue(Str::isUuid($session->id));
    }

    #[Test]
    public function it_has_correct_fillable_attributes(): void
    {
        $session = new AnalyticsSession;

        $expectedFillable = [
            'session_group_id',
            'user_id',
            'guest_sid',
            'device_type',
            'user_agent',
            'started_at',
            'last_activity_at',
            'ended_at',
            'rapid_click_count',
            'rage_clicks',
            'form_abandonment',
            'frustration_score',
            'scroll_depth',
            'session_actions',
            'inferred_intent',
            'behavior_snapshot',
            'last_actions_before_exit',
            'total_events',
            'total_pages_viewed',
        ];

        $this->assertEquals($expectedFillable, $session->getFillable());
    }

    #[Test]
    public function it_casts_datetime_fields_correctly(): void
    {
        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => '2025-01-15 10:30:00',
            'last_activity_at' => '2025-01-15 11:00:00',
            'ended_at' => '2025-01-15 12:00:00',
        ]);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $session->started_at);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $session->last_activity_at);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $session->ended_at);
    }

    #[Test]
    public function it_casts_boolean_fields_correctly(): void
    {
        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now(),
            'last_activity_at' => now(),
            'form_abandonment' => 1,
        ]);

        $this->assertTrue($session->form_abandonment);
        $this->assertIsBool($session->form_abandonment);
    }

    #[Test]
    public function it_casts_json_fields_to_arrays(): void
    {
        $sessionActions = [
            ['type' => 'click', 'target' => '#btn', 't' => 1.5],
            ['type' => 'scroll', 'target' => null, 't' => 2.3],
        ];

        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now(),
            'last_activity_at' => now(),
            'session_actions' => $sessionActions,
            'behavior_snapshot' => ['page' => '/home', 'density' => 0.5],
            'last_actions_before_exit' => [['type' => 'click', 't' => 10]],
        ]);

        $this->assertIsArray($session->session_actions);
        $this->assertEquals($sessionActions, $session->session_actions);
        $this->assertIsArray($session->behavior_snapshot);
        $this->assertIsArray($session->last_actions_before_exit);
    }

    #[Test]
    public function it_casts_decimal_fields_correctly(): void
    {
        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now(),
            'last_activity_at' => now(),
            'frustration_score' => 0.75,
            'scroll_depth' => 0.85,
        ]);

        $this->assertEquals('0.75', $session->frustration_score);
        $this->assertEquals('0.85', $session->scroll_depth);
    }

    #[Test]
    public function it_belongs_to_user(): void
    {
        $user = User::factory()->create();

        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $this->assertInstanceOf(User::class, $session->user);
        $this->assertEquals($user->id, $session->user->id);
    }

    #[Test]
    public function it_can_have_null_user(): void
    {
        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'user_id' => null,
            'guest_sid' => 'guest-123',
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $this->assertNull($session->user);
        $this->assertEquals('guest-123', $session->guest_sid);
    }

    #[Test]
    public function it_has_many_events(): void
    {
        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        // Create related events
        AnalyticsEvent::create([
            'session_id' => $session->id,
            'event' => 'page_view',
            'meta' => ['page' => '/home'],
        ]);

        AnalyticsEvent::create([
            'session_id' => $session->id,
            'event' => 'click',
            'meta' => ['target' => '#submit'],
        ]);

        $this->assertCount(2, $session->events);
        $this->assertInstanceOf(AnalyticsEvent::class, $session->events->first());
    }

    #[Test]
    public function it_has_default_values_for_numeric_fields(): void
    {
        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $session->refresh();

        $this->assertEquals(0, $session->rapid_click_count);
        $this->assertEquals(0, $session->rage_clicks);
        $this->assertFalse($session->form_abandonment);
        $this->assertEquals('0.00', $session->frustration_score);
        $this->assertEquals(0, $session->total_events);
        $this->assertEquals(0, $session->total_pages_viewed);
    }

    #[Test]
    public function it_stores_device_type(): void
    {
        $deviceTypes = ['desktop', 'mobile', 'tablet', 'bot'];

        foreach ($deviceTypes as $type) {
            $session = AnalyticsSession::create([
                'id' => Str::uuid()->toString(),
                'device_type' => $type,
                'started_at' => now(),
                'last_activity_at' => now(),
            ]);

            $this->assertEquals($type, $session->device_type);
        }
    }

    #[Test]
    public function it_stores_session_group_id_for_multi_tab(): void
    {
        $groupId = Str::uuid()->toString();

        $session1 = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'session_group_id' => $groupId,
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $session2 = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'session_group_id' => $groupId,
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $groupedSessions = AnalyticsSession::where('session_group_id', $groupId)->get();

        $this->assertCount(2, $groupedSessions);
    }

    #[Test]
    public function it_tracks_frustration_metrics(): void
    {
        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now(),
            'last_activity_at' => now(),
            'rage_clicks' => 5,
            'rapid_click_count' => 12,
            'form_abandonment' => true,
            'frustration_score' => 0.85,
        ]);

        $this->assertEquals(5, $session->rage_clicks);
        $this->assertEquals(12, $session->rapid_click_count);
        $this->assertTrue($session->form_abandonment);
        $this->assertEquals('0.85', $session->frustration_score);
    }

    #[Test]
    public function it_does_not_use_timestamps(): void
    {
        $session = new AnalyticsSession;

        $this->assertFalse($session->timestamps);
    }
}
