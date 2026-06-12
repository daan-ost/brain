<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class ApiV1RestrictNewUsers
{
    /**
     * Handle an incoming request.
     *
     * Blocks users who registered after the API v1 go-live date from using the legacy API.
     * This middleware is optional and can be enabled when v2 is ready.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only enforce if API_V1_GO_LIVE_DATE is configured
        $goLiveDate = config('api.v1_go_live_date');

        if (! $goLiveDate) {
            // No restriction configured, allow all users
            return $next($request);
        }

        $user = $request->user();

        if (! $user) {
            return response()->json([
                'error' => 'Unauthenticated',
            ], 401);
        }

        // Parse go-live date
        $cutoffDate = Carbon::parse($goLiveDate);

        // Check if user registered after the go-live date
        if ($user->created_at->isAfter($cutoffDate)) {
            return response()->json([
                'error' => 'API v1 is not available for new users. Please use API v2.',
                'message' => 'Your account was created after the API v1 cutoff date. Please migrate to API v2 for full functionality.',
                'cutoff_date' => $cutoffDate->toIso8601String(),
                'documentation_url' => config('api.v2_documentation_url', 'https://example.com/api/v2'),
            ], 403);
        }

        return $next($request);
    }
}
