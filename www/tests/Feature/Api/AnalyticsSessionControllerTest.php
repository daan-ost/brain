<?php

namespace Tests\Feature\Api;

use App\Models\AnalyticsSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AnalyticsSessionControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure analytics is enabled
        config(['analytics.client_tracking_enabled' => true]);
    }

    #[Test]
    public function it_requires_session_id(): void
    {
        $response = $this->postJson('/api/analytics/session', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['session_id']);
    }

    #[Test]
    public function it_requires_valid_uuid_for_session_id(): void
    {
        $response = $this->postJson('/api/analytics/session', [
            'session_id' => 'not-a-uuid',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['session_id']);
    }

    #[Test]
    public function it_accepts_valid_session_update(): void
    {
        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $response = $this->postJson('/api/analytics/session', [
            'session_id' => $session->id,
            'rage_clicks' => 3,
        ]);

        $response->assertOk()
            ->assertJson(['ok' => true]);

        $session->refresh();
        $this->assertEquals(3, $session->rage_clicks);
    }

    #[Test]
    public function it_validates_rage_clicks_is_non_negative(): void
    {
        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $response = $this->postJson('/api/analytics/session', [
            'session_id' => $session->id,
            'rage_clicks' => -1,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['rage_clicks']);
    }

    #[Test]
    public function it_validates_scroll_depth_range(): void
    {
        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        // Greater than 1 should fail
        $response = $this->postJson('/api/analytics/session', [
            'session_id' => $session->id,
            'scroll_depth' => 1.5,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['scroll_depth']);

        // Less than 0 should fail
        $response = $this->postJson('/api/analytics/session', [
            'session_id' => $session->id,
            'scroll_depth' => -0.1,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['scroll_depth']);
    }

    #[Test]
    public function it_accepts_valid_scroll_depth(): void
    {
        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $response = $this->postJson('/api/analytics/session', [
            'session_id' => $session->id,
            'scroll_depth' => 0.75,
        ]);

        $response->assertOk();
    }

    #[Test]
    public function it_validates_actions_array(): void
    {
        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $response = $this->postJson('/api/analytics/session', [
            'session_id' => $session->id,
            'actions' => 'not-an-array',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['actions']);
    }

    #[Test]
    public function it_validates_actions_max_count(): void
    {
        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        // Create 51 actions (max is 50)
        $actions = [];
        for ($i = 0; $i < 51; $i++) {
            $actions[] = ['type' => 'click', 'target' => '#btn', 't' => $i];
        }

        $response = $this->postJson('/api/analytics/session', [
            'session_id' => $session->id,
            'actions' => $actions,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['actions']);
    }

    #[Test]
    public function it_validates_action_type_is_required(): void
    {
        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $response = $this->postJson('/api/analytics/session', [
            'session_id' => $session->id,
            'actions' => [
                ['target' => '#btn', 't' => 1], // missing 'type'
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['actions.0.type']);
    }

    #[Test]
    public function it_validates_action_timestamp_is_required(): void
    {
        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $response = $this->postJson('/api/analytics/session', [
            'session_id' => $session->id,
            'actions' => [
                ['type' => 'click', 'target' => '#btn'], // missing 't'
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['actions.0.t']);
    }

    #[Test]
    public function it_accepts_valid_actions(): void
    {
        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $response = $this->postJson('/api/analytics/session', [
            'session_id' => $session->id,
            'actions' => [
                ['type' => 'click', 'target' => '#btn', 't' => 1.5],
                ['type' => 'scroll', 'target' => null, 't' => 2.3],
            ],
        ]);

        $response->assertOk();

        $session->refresh();
        $this->assertCount(2, $session->session_actions);
    }

    #[Test]
    public function it_accepts_exit_actions(): void
    {
        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $response = $this->postJson('/api/analytics/session', [
            'session_id' => $session->id,
            'exit_actions' => [
                ['type' => 'beforeunload', 't' => 100],
            ],
        ]);

        $response->assertOk();

        $session->refresh();
        $this->assertNotNull($session->ended_at);
        $this->assertNotEmpty($session->last_actions_before_exit);
    }

    #[Test]
    public function it_respects_kill_switch(): void
    {
        config(['analytics.client_tracking_enabled' => false]);

        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now(),
            'last_activity_at' => now(),
            'rage_clicks' => 0,
        ]);

        $response = $this->postJson('/api/analytics/session', [
            'session_id' => $session->id,
            'rage_clicks' => 5,
        ]);

        $response->assertOk()
            ->assertJson(['ok' => true, 'skipped' => true]);

        // Session should NOT be updated
        $session->refresh();
        $this->assertEquals(0, $session->rage_clicks);
    }

    #[Test]
    public function it_handles_nonexistent_session_gracefully(): void
    {
        $response = $this->postJson('/api/analytics/session', [
            'session_id' => Str::uuid()->toString(),
            'rage_clicks' => 3,
        ]);

        $response->assertOk()
            ->assertJson(['ok' => true]);
    }

    #[Test]
    public function it_accepts_session_group_id(): void
    {
        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $groupId = Str::uuid()->toString();

        $response = $this->postJson('/api/analytics/session', [
            'session_id' => $session->id,
            'session_group_id' => $groupId,
        ]);

        $response->assertOk();

        $session->refresh();
        $this->assertEquals($groupId, $session->session_group_id);
    }

    #[Test]
    public function it_validates_session_group_id_is_uuid(): void
    {
        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $response = $this->postJson('/api/analytics/session', [
            'session_id' => $session->id,
            'session_group_id' => 'not-a-uuid',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['session_group_id']);
    }

    #[Test]
    public function it_validates_form_abandonment_is_boolean(): void
    {
        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $response = $this->postJson('/api/analytics/session', [
            'session_id' => $session->id,
            'form_abandonment' => 'yes', // Should be boolean
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['form_abandonment']);
    }

    #[Test]
    public function it_accepts_boolean_form_abandonment(): void
    {
        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $response = $this->postJson('/api/analytics/session', [
            'session_id' => $session->id,
            'form_abandonment' => true,
        ]);

        $response->assertOk();

        $session->refresh();
        $this->assertTrue($session->form_abandonment);
    }

    #[Test]
    public function it_accepts_combined_update(): void
    {
        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $response = $this->postJson('/api/analytics/session', [
            'session_id' => $session->id,
            'session_group_id' => Str::uuid()->toString(),
            'rage_clicks' => 3,
            'rapid_click_count' => 10,
            'form_abandonment' => true,
            'scroll_depth' => 0.85,
            'actions' => [
                ['type' => 'click', 'target' => '#submit', 't' => 5.5],
            ],
        ]);

        $response->assertOk();

        $session->refresh();
        $this->assertEquals(3, $session->rage_clicks);
        $this->assertEquals(10, $session->rapid_click_count);
        $this->assertTrue($session->form_abandonment);
        $this->assertEquals('0.85', $session->scroll_depth);
        $this->assertCount(1, $session->session_actions);
    }

    #[Test]
    public function it_validates_action_target_max_length(): void
    {
        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $response = $this->postJson('/api/analytics/session', [
            'session_id' => $session->id,
            'actions' => [
                ['type' => 'click', 'target' => str_repeat('a', 101), 't' => 1], // max is 100
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['actions.0.target']);
    }

    #[Test]
    public function it_validates_action_type_max_length(): void
    {
        $session = AnalyticsSession::create([
            'id' => Str::uuid()->toString(),
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $response = $this->postJson('/api/analytics/session', [
            'session_id' => $session->id,
            'actions' => [
                ['type' => str_repeat('a', 21), 'target' => '#btn', 't' => 1], // max is 20
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['actions.0.type']);
    }
}
