<?php

namespace Tests\Feature\Api\AI;

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiAnalyticsControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $validInternalToken = 'test-internal-token-for-ai-api';

    private User $user;

    private string $apiToken;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ai.internal_token' => $this->validInternalToken]);

        $this->user = User::factory()->create();
        $token = $this->user->createToken('test-token');
        $this->apiToken = $token->plainTextToken;
    }

    private function getAuthHeaders(): array
    {
        return [
            'X-API-Key' => $this->apiToken,
            'X-Internal-AI-Token' => $this->validInternalToken,
        ];
    }

    // ==================== Authentication Tests ====================

    public function test_summary_requires_authentication(): void
    {
        $response = $this->getJson('/api/ai/analytics/summary?from=2025-01-01&to=2025-01-15');

        $response->assertStatus(401);
    }

    public function test_user_diagnostics_requires_authentication(): void
    {
        $response = $this->getJson('/api/ai/analytics/user-diagnostics?user_id=1&from=2025-01-01&to=2025-01-15');

        $response->assertStatus(401);
    }

    public function test_summary_works_with_valid_authentication(): void
    {
        $response = $this->withHeaders($this->getAuthHeaders())
            ->getJson('/api/ai/analytics/summary?from=2025-01-01&to=2025-01-15');

        $response->assertStatus(200);
    }

    // ==================== Summary Endpoint Tests ====================

    public function test_summary_requires_from_parameter(): void
    {
        $response = $this->withHeaders($this->getAuthHeaders())
            ->getJson('/api/ai/analytics/summary?to=2025-01-15');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['from']);
    }

    public function test_summary_requires_to_parameter(): void
    {
        $response = $this->withHeaders($this->getAuthHeaders())
            ->getJson('/api/ai/analytics/summary?from=2025-01-01');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['to']);
    }

    public function test_summary_rejects_invalid_date_format(): void
    {
        $response = $this->withHeaders($this->getAuthHeaders())
            ->getJson('/api/ai/analytics/summary?from=invalid&to=2025-01-15');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['from']);
    }

    public function test_summary_rejects_from_after_to(): void
    {
        $response = $this->withHeaders($this->getAuthHeaders())
            ->getJson('/api/ai/analytics/summary?from=2025-01-15&to=2025-01-01');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['to']);
    }

    public function test_summary_rejects_range_over_30_days(): void
    {
        $response = $this->withHeaders($this->getAuthHeaders())
            ->getJson('/api/ai/analytics/summary?from=2025-01-01&to=2025-03-01');

        $response->assertStatus(422);
        $response->assertJson(['error' => 'Date range cannot exceed 30 days']);
    }

    public function test_summary_returns_correct_json_structure(): void
    {
        $response = $this->withHeaders($this->getAuthHeaders())
            ->getJson('/api/ai/analytics/summary?from=2025-01-01&to=2025-01-15');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'period' => ['from', 'to'],
            'sessions' => [
                'total',
                'unique_users',
                'avg_duration',
                'avg_scroll_depth',
                'actions_per_session',
                'rage_clicks',
                'rage_click_rate',
                'bounce_rate',
                'error_rate',
            ],
            'pages' => [
                'top_pages',
                'exit_map',
            ],
            'errors' => [
                'count',
                'top_messages',
                'api_error_rate',
            ],
            'performance' => [
                'avg_load_time',
                'avg_first_interaction',
                'slow_sessions',
            ],
        ]);
    }

    public function test_summary_returns_correct_data(): void
    {
        // Create test data
        $session = AnalyticsSession::create([
            'user_id' => $this->user->id,
            'started_at' => '2025-01-05 10:00:00',
            'last_activity_at' => '2025-01-05 10:05:00',
            'ended_at' => '2025-01-05 10:05:00',
            'scroll_depth' => 0.80,
            'rage_clicks' => 3,
            'total_events' => 15,
            'total_pages_viewed' => 4,
        ]);

        AnalyticsEvent::create([
            'user_id' => $this->user->id,
            'session_id' => $session->id,
            'event' => 'page_view',
            'meta' => ['url' => '/upload'],
            'created_at' => '2025-01-05 10:00:00',
        ]);

        $response = $this->withHeaders($this->getAuthHeaders())
            ->getJson('/api/ai/analytics/summary?from=2025-01-01&to=2025-01-15');

        $response->assertStatus(200);
        $response->assertJsonPath('sessions.total', 1);
        $response->assertJsonPath('sessions.rage_clicks', 3);
        $response->assertJsonPath('sessions.avg_scroll_depth', 80);
    }

    // ==================== User Diagnostics Endpoint Tests ====================

    public function test_user_diagnostics_requires_user_id(): void
    {
        $response = $this->withHeaders($this->getAuthHeaders())
            ->getJson('/api/ai/analytics/user-diagnostics?from=2025-01-01&to=2025-01-15');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['user_id']);
    }

    public function test_user_diagnostics_requires_valid_user_id(): void
    {
        $response = $this->withHeaders($this->getAuthHeaders())
            ->getJson('/api/ai/analytics/user-diagnostics?user_id=999999&from=2025-01-01&to=2025-01-15');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['user_id']);
    }

    public function test_user_diagnostics_requires_from_parameter(): void
    {
        $response = $this->withHeaders($this->getAuthHeaders())
            ->getJson('/api/ai/analytics/user-diagnostics?user_id='.$this->user->id.'&to=2025-01-15');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['from']);
    }

    public function test_user_diagnostics_rejects_range_over_30_days(): void
    {
        $response = $this->withHeaders($this->getAuthHeaders())
            ->getJson('/api/ai/analytics/user-diagnostics?user_id='.$this->user->id.'&from=2025-01-01&to=2025-03-01');

        $response->assertStatus(422);
        $response->assertJson(['error' => 'Date range cannot exceed 30 days']);
    }

    public function test_user_diagnostics_returns_correct_json_structure(): void
    {
        $response = $this->withHeaders($this->getAuthHeaders())
            ->getJson('/api/ai/analytics/user-diagnostics?user_id='.$this->user->id.'&from=2025-01-01&to=2025-01-15');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'user_id',
            'period' => ['from', 'to'],
            'recent_sessions',
            'errors',
            'rage_clicks',
            'page_flow',
        ]);
    }

    public function test_user_diagnostics_returns_user_specific_data(): void
    {
        $otherUser = User::factory()->create();

        // Create session for requested user
        AnalyticsSession::create([
            'user_id' => $this->user->id,
            'started_at' => '2025-01-05 10:00:00',
            'last_activity_at' => '2025-01-05 10:05:00',
            'scroll_depth' => 0.5,
            'total_pages_viewed' => 3,
        ]);

        // Create session for other user
        AnalyticsSession::create([
            'user_id' => $otherUser->id,
            'started_at' => '2025-01-05 10:00:00',
            'last_activity_at' => '2025-01-05 10:05:00',
            'scroll_depth' => 0.7,
            'total_pages_viewed' => 5,
        ]);

        $response = $this->withHeaders($this->getAuthHeaders())
            ->getJson('/api/ai/analytics/user-diagnostics?user_id='.$this->user->id.'&from=2025-01-01&to=2025-01-15');

        $response->assertStatus(200);
        $response->assertJsonPath('user_id', $this->user->id);
        $response->assertJsonCount(1, 'recent_sessions');
    }

    public function test_user_diagnostics_returns_max_3_recent_sessions(): void
    {
        // Create 5 sessions
        for ($i = 0; $i < 5; $i++) {
            AnalyticsSession::create([
                'user_id' => $this->user->id,
                'started_at' => '2025-01-0'.($i + 1).' 10:00:00',
                'last_activity_at' => '2025-01-0'.($i + 1).' 10:05:00',
                'scroll_depth' => 0.5,
            ]);
        }

        $response = $this->withHeaders($this->getAuthHeaders())
            ->getJson('/api/ai/analytics/user-diagnostics?user_id='.$this->user->id.'&from=2025-01-01&to=2025-01-15');

        $response->assertStatus(200);
        $response->assertJsonCount(3, 'recent_sessions');
    }

    // ==================== Logging Tests ====================

    public function test_successful_request_is_logged(): void
    {
        $this->withHeaders($this->getAuthHeaders())
            ->getJson('/api/ai/analytics/summary?from=2025-01-01&to=2025-01-15');

        $this->assertDatabaseHas('ai_api_logs', [
            'user_id' => $this->user->id,
            'endpoint' => 'api/ai/analytics/summary',
            'success' => true,
        ]);
    }

    public function test_failed_auth_request_is_logged(): void
    {
        $this->getJson('/api/ai/analytics/summary?from=2025-01-01&to=2025-01-15');

        $this->assertDatabaseHas('ai_api_logs', [
            'user_id' => null,
            'endpoint' => 'api/ai/analytics/summary',
            'success' => false,
        ]);
    }
}
