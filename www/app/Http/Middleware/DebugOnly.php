<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Debug-Only Middleware
 *
 * Restricts access to debug/development routes.
 * NEVER allows access in production environment.
 */
class DebugOnly
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // HARD BLOCK: Production environment only
        // Staging is allowed for testing purposes
        $env = config('app.env');
        if ($env === 'production') {
            abort(404);
        }

        // Development/testing: require authentication (except local)
        if ($env !== 'local' && ! auth()->check()) {
            return redirect()->route('login')
                ->with('error', 'Please log in to access development tools.');
        }

        return $next($request);
    }
}
