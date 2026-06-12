<?php

namespace App\Http\Middleware;

use App\Services\TwoFactorService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTwoFactorChallenged
{
    public function __construct(
        protected TwoFactorService $twoFactorService
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $guard = 'web'): Response
    {
        $user = $request->user($guard);

        if (! $user) {
            return $next($request);
        }

        // If user doesn't have 2FA enabled, allow through
        if (! $user->hasTwoFactorEnabled()) {
            return $next($request);
        }

        // If already verified this session, allow through
        if ($request->session()->get('two_factor_verified_' . $guard)) {
            return $next($request);
        }

        // Check for remember token
        if ($this->twoFactorService->hasValidRememberToken($user, $guard)) {
            $request->session()->put('two_factor_verified_' . $guard, true);
            return $next($request);
        }

        // Store intended URL and redirect to challenge
        $request->session()->put('two_factor_intended_url', $request->url());

        if ($guard === 'admin') {
            return redirect()->route('filament.admin.two-factor.challenge');
        }

        return redirect()->route('two-factor.challenge');
    }
}
