<?php

namespace App\Http\Middleware;

use App\Services\AnalyticsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackApiTokenUsage
{
    /**
     * Handle an incoming request.
     *
     * Track API token usage for analytics when authenticated via Sanctum token.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only log if user is authenticated via Sanctum token (not session)
        $user = $request->user();
        if ($user && $request->bearerToken()) {
            $token = $user->currentAccessToken();

            if ($token) {
                AnalyticsService::log('api_token_used', [
                    'token_id' => $token->id,
                    'token_name' => $token->name,
                    'endpoint' => $request->path(),
                    'method' => $request->method(),
                ]);

                // Update last_used_at on the token
                $token->update(['last_used_at' => now()]);
            }
        }

        return $response;
    }
}
