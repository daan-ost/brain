<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Sentry\State\Scope;

class SentryContext
{
    public function handle(Request $request, Closure $next)
    {
        if (! app()->bound('sentry')) {
            return $next($request);
        }

        \Sentry\configureScope(function (Scope $scope) use ($request): void {
            if ($user = $request->user()) {
                // Alleen ID — geen email/naam (PII-vrij).
                $scope->setUser([
                    'id' => (string) $user->id,
                ]);
            }

            // Organization-tag voor multi-tenant filtering in Sentry UI.
            if ($request->hasSession() && $orgId = $request->session()->get('current_organization_id')) {
                $scope->setTag('organization_id', (string) $orgId);
            }

            $scope->setTag('route', $request->route()?->getName() ?? 'unknown');
        });

        return $next($request);
    }
}
