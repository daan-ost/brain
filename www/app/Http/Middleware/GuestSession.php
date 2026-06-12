<?php

namespace App\Http\Middleware;

use App\Services\AnalyticsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GuestSession
{
    public function handle(Request $request, Closure $next): Response
    {
        // Skip guest session handling for admin routes
        if ($request->is('beheer') || $request->is('beheer/*')) {
            return $next($request);
        }

        // First check if there's a guest_sid cookie (persists across sessions)
        // If cookie exists but session doesn't, restore it to session
        $cookieGuestSid = $request->cookie('guest_sid');
        if ($cookieGuestSid && ! $request->session()->has('guest_sid')) {
            session(['guest_sid' => $cookieGuestSid]);
        }

        // If no guest_sid in session or cookie, create a new one
        if (! $request->session()->has('guest_sid')) {
            AnalyticsService::getOrCreateGuestSid();
        }

        $response = $next($request);

        // Set HTTP-only, secure cookie for guest session
        // Only set cookies if the response supports them (not for StreamedResponse)
        if (! $request->cookie('guest_sid') && method_exists($response, 'cookie')) {
            $guestSid = session('guest_sid');
            $response->cookie(
                'guest_sid',
                $guestSid,
                30 * 24 * 60, // 30 days in minutes
                '/',
                null,
                $request->isSecure(),
                true // HTTP only
            );
        }

        return $response;
    }
}
