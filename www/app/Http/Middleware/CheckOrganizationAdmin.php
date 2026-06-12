<?php

namespace App\Http\Middleware;

use App\Enums\OrganizationRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckOrganizationAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        // Get user's organizations
        $organizations = $user->organizations()->get();

        if ($organizations->isEmpty()) {
            return redirect()->route('profile.account')
                ->with('error', 'You are not a member of any organization.');
        }

        // Check if user is admin in any organization
        $isAdmin = $organizations->contains(function ($org) {
            return $org->pivot->role === OrganizationRole::Owner;
        });

        if (! $isAdmin) {
            return redirect()->route('profile.account')
                ->with('error', 'You do not have permission to access organization settings. Only organization administrators can manage these settings.');
        }

        // Share organization data with all views
        $currentOrganization = $organizations->first(fn ($org) => $org->pivot->role === OrganizationRole::Owner);
        view()->share('currentOrganization', $currentOrganization);
        view()->share('userOrganizations', $organizations);
        view()->share('isOrganizationAdmin', true);

        return $next($request);
    }
}
