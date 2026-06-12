<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckMollieAccess
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if (! $user) {
            abort(401, 'Authentication required');
        }

        // Check if user has admin access
        $hasAccess = false;

        // First check is_admin flag
        if ($user->is_admin) {
            $hasAccess = true;
        } else {
            try {
                $hasAccess = $user->hasRole('superadmin') || $user->hasRole('admin');
            } catch (\Exception $e) {
                // Role system not available, rely on is_admin flag only
                $hasAccess = false;
            }
        }

        if (! $hasAccess) {
            abort(403, 'Access denied to Mollie payment management');
        }

        return $next($request);
    }
}
