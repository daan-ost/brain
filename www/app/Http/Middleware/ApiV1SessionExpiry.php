<?php

namespace App\Http\Middleware;

use App\Models\ApiV1Session;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiV1SessionExpiry
{
    /**
     * Handle an incoming request.
     *
     * Checks if the API session has expired and updates last activity timestamp.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Get session ID from route parameter
        $sessionId = $request->route('session');

        if ($sessionId) {
            // Load session (if it's a model binding, it's already loaded)
            $session = $sessionId instanceof ApiV1Session
                ? $sessionId
                : ApiV1Session::find($sessionId);

            if (! $session) {
                return response()->json([
                    'error' => 'Session not found',
                ], 404);
            }

            // Check if session is expired
            if ($session->isExpired()) {
                return response()->json([
                    'error' => 'Session has expired',
                    'expired_at' => $session->expires_at->toIso8601String(),
                ], 410); // 410 Gone - resource existed but is no longer available
            }

            // Check if session belongs to authenticated user
            if ($session->user_id !== $request->user()->id) {
                return response()->json([
                    'error' => 'Unauthorized access to session',
                ], 403);
            }

            // Update last activity timestamp
            $session->updateLastActivity();
        }

        return $next($request);
    }
}
