<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class ApiV1DualAuth
{
    /**
     * Handle an incoming request.
     *
     * Supports both legacy username/password authentication (HTTP Basic Auth)
     * and modern Sanctum token authentication for backwards compatibility.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // First, try Sanctum token authentication (Bearer token)
        if ($request->bearerToken()) {
            $user = Auth::guard('sanctum')->user();

            if ($user) {
                Auth::setUser($user);

                return $next($request);
            }

            return response()->json([
                'error' => 'Invalid or expired token',
            ], 401);
        }

        // Second, try HTTP Basic Auth (email/password in headers)
        // Parse Authorization header manually for better test compatibility
        $authHeader = $request->header('Authorization');

        if ($authHeader && str_starts_with($authHeader, 'Basic ')) {
            $credentials = base64_decode(substr($authHeader, 6));
            if ($credentials && str_contains($credentials, ':')) {
                [$email, $password] = explode(':', $credentials, 2);

                $user = User::where('email', $email)->first();

                if ($user && Hash::check($password, $user->password)) {
                    Auth::setUser($user);

                    return $next($request);
                }

                return response()->json([
                    'error' => 'Invalid credentials',
                ], 401);
            }
        }

        // Third, try VERY legacy username/password in POST body
        // This is specifically for the POST /sessions endpoint used by legacy PHP SDK
        if ($request->isMethod('POST') &&
            $request->has('username') &&
            $request->has('password')) {

            $username = $request->input('username');
            $password = $request->input('password');

            $user = User::where('email', $username)->first();

            if ($user && Hash::check($password, $user->password)) {
                Auth::setUser($user);

                return $next($request);
            }

            return response()->json([
                'error' => 'Invalid credentials',
            ], 401);
        }

        // Fourth, try session ID authentication (legacy SDK compatibility)
        // Legacy SDK sends only session_id in URL after initial login
        // Extract session ID from URL patterns like /sessions/{uuid}/...
        if (preg_match('#/sessions/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})#i', $request->path(), $matches)) {
            $sessionId = $matches[1];

            $session = \App\Models\ApiV1Session::find($sessionId);

            if ($session) {
                // Check if session is not expired
                if (! $session->isExpired()) {
                    Auth::setUser($session->user);

                    return $next($request);
                }

                // Session expired - will be caught by SessionExpiry middleware
                // But we still need to authenticate to reach that middleware
                Auth::setUser($session->user);

                return $next($request);
            }
        }

        // Fifth, try process result ID authentication (for download endpoints)
        // Extract process result ID from URL patterns like /process/{uuid} or /process/{uuid}/download
        if (preg_match('#/process/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})#i', $request->path(), $matches)) {
            $processResultId = $matches[1];

            $processResult = \App\Models\ApiV1ProcessResult::find($processResultId);

            if ($processResult && $processResult->session) {
                Auth::setUser($processResult->session->user);

                return $next($request);
            }
        }

        // No authentication method provided
        return response()->json([
            'error' => 'Authentication required. Provide Bearer token, Basic Auth, or username/password.',
        ], 401);
    }
}
