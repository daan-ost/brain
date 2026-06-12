<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\InternalOnly;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class InternalOnlyTest extends TestCase
{
    use RefreshDatabase;

    private InternalOnly $middleware;

    private string $validInternalToken = 'test-internal-token-123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new InternalOnly;
        config(['services.ai.internal_token' => $this->validInternalToken]);
    }

    public function test_rejects_request_without_any_headers(): void
    {
        $request = Request::create('/api/ai/analytics/summary', 'GET');

        $response = $this->middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertEquals(['error' => 'Unauthorized'], $response->getData(true));
    }

    public function test_rejects_request_with_only_internal_token(): void
    {
        $request = Request::create('/api/ai/analytics/summary', 'GET');
        $request->headers->set('X-Internal-AI-Token', $this->validInternalToken);

        $response = $this->middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertEquals(401, $response->getStatusCode());
    }

    public function test_rejects_request_with_only_api_key(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token');

        $request = Request::create('/api/ai/analytics/summary', 'GET');
        $request->headers->set('X-API-Key', $token->plainTextToken);

        $response = $this->middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertEquals(401, $response->getStatusCode());
    }

    public function test_rejects_request_with_invalid_internal_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token');

        $request = Request::create('/api/ai/analytics/summary', 'GET');
        $request->headers->set('X-API-Key', $token->plainTextToken);
        $request->headers->set('X-Internal-AI-Token', 'wrong-token');

        $response = $this->middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertEquals(401, $response->getStatusCode());
    }

    public function test_rejects_request_with_invalid_api_key(): void
    {
        $request = Request::create('/api/ai/analytics/summary', 'GET');
        $request->headers->set('X-API-Key', 'invalid-api-key');
        $request->headers->set('X-Internal-AI-Token', $this->validInternalToken);

        $response = $this->middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertEquals(401, $response->getStatusCode());
    }

    public function test_accepts_request_with_valid_dual_authentication(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token');

        $request = Request::create('/api/ai/analytics/summary', 'GET');
        $request->headers->set('X-API-Key', $token->plainTextToken);
        $request->headers->set('X-Internal-AI-Token', $this->validInternalToken);

        $response = $this->middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(['ok' => true], $response->getData(true));
    }

    public function test_attaches_user_to_request(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token');

        $request = Request::create('/api/ai/analytics/summary', 'GET');
        $request->headers->set('X-API-Key', $token->plainTextToken);
        $request->headers->set('X-Internal-AI-Token', $this->validInternalToken);

        $capturedUser = null;
        $this->middleware->handle($request, function ($req) use (&$capturedUser) {
            $capturedUser = $req->user();

            return response()->json(['ok' => true]);
        });

        $this->assertNotNull($capturedUser);
        $this->assertEquals($user->id, $capturedUser->id);
    }

    public function test_logs_successful_request(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token');

        $request = Request::create('/api/ai/analytics/summary', 'GET');
        $request->headers->set('X-API-Key', $token->plainTextToken);
        $request->headers->set('X-Internal-AI-Token', $this->validInternalToken);

        $this->middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertDatabaseHas('ai_api_logs', [
            'user_id' => $user->id,
            'endpoint' => 'api/ai/analytics/summary',
            'success' => true,
        ]);
    }

    public function test_logs_failed_request(): void
    {
        $request = Request::create('/api/ai/analytics/summary', 'GET');

        $this->middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertDatabaseHas('ai_api_logs', [
            'user_id' => null,
            'endpoint' => 'api/ai/analytics/summary',
            'success' => false,
        ]);
    }

    public function test_rejects_expired_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token');

        // Manually expire the token
        PersonalAccessToken::where('token', hash('sha256', explode('|', $token->plainTextToken)[1]))
            ->update(['expires_at' => now()->subDay()]);

        $request = Request::create('/api/ai/analytics/summary', 'GET');
        $request->headers->set('X-API-Key', $token->plainTextToken);
        $request->headers->set('X-Internal-AI-Token', $this->validInternalToken);

        $response = $this->middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertEquals(['error' => 'Token expired'], $response->getData(true));
    }

    public function test_updates_token_last_used_at(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token');

        $request = Request::create('/api/ai/analytics/summary', 'GET');
        $request->headers->set('X-API-Key', $token->plainTextToken);
        $request->headers->set('X-Internal-AI-Token', $this->validInternalToken);

        $this->middleware->handle($request, fn () => response()->json(['ok' => true]));

        $tokenModel = PersonalAccessToken::where('token', hash('sha256', explode('|', $token->plainTextToken)[1]))->first();
        $this->assertNotNull($tokenModel->last_used_at);
    }
}
