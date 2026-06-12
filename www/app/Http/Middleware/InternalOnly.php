<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class InternalOnly
{
    /**
     * Handle an incoming request.
     *
     * Validates dual authentication:
     * - X-API-Key: Valid personal access token from /profile/api-tokens
     * - X-Internal-AI-Token: Matches env('AI_INTERNAL_TOKEN')
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);
        $apiKey = $request->header('X-API-Key');
        $internalToken = $request->header('X-Internal-AI-Token');

        // Validate internal AI token
        if (empty($internalToken) || $internalToken !== config('services.ai.internal_token')) {
            $this->logRequest($request, null, false, $startTime);

            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Validate API key
        if (empty($apiKey)) {
            $this->logRequest($request, null, false, $startTime);

            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Find token and user
        $token = PersonalAccessToken::findToken($apiKey);
        if (! $token || ! $token->tokenable) {
            $this->logRequest($request, null, false, $startTime);

            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Check token expiration
        if ($token->expires_at && $token->expires_at->isPast()) {
            $this->logRequest($request, null, false, $startTime);

            return response()->json(['error' => 'Token expired'], 401);
        }

        // Attach user to request for controller access
        $request->merge(['authenticated_user' => $token->tokenable]);
        $request->setUserResolver(fn () => $token->tokenable);

        // Update token last used
        $token->forceFill(['last_used_at' => now()])->save();

        // Execute request
        $response = $next($request);

        // Log successful request
        $this->logRequest($request, $token->tokenable->id, true, $startTime);

        return $response;
    }

    /**
     * Log API request to ai_api_logs table.
     */
    private function logRequest(Request $request, ?int $userId, bool $success, float $startTime): void
    {
        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        DB::table('ai_api_logs')->insert([
            'user_id' => $userId,
            'endpoint' => $request->path(),
            'success' => $success,
            'requester_ip' => $request->ip(),
            'duration_ms' => $durationMs,
            'created_at' => now(),
        ]);
    }
}
