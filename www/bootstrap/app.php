<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust proxies configuration
        // In production: Set TRUSTED_PROXIES env var to your load balancer/CDN IPs
        // In local dev: Set TRUSTED_PROXIES=* if needed for HTTPS via reverse proxy
        $trustedProxies = env('TRUSTED_PROXIES');

        $middleware->trustProxies(
            at: $trustedProxies === '*' ? '*' : ($trustedProxies ? explode(',', $trustedProxies) : []),
            headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR |
                    \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST |
                    \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT |
                    \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO |
                    \Illuminate\Http\Request::HEADER_X_FORWARDED_AWS_ELB
        );

        $middleware->web([
            \App\Http\Middleware\SecurityHeaders::class,
            \App\Http\Middleware\GuestSession::class,
            \App\Http\Middleware\SetLocale::class,
            \App\Http\Middleware\DetectGuestLocale::class,
            \App\Http\Middleware\RedirectToPreferredLocale::class,
            \App\Http\Middleware\SentryContext::class,
        ]);

        // Add SetLocale and SentryContext to API middleware
        $middleware->api([
            \App\Http\Middleware\SetLocale::class,
            \App\Http\Middleware\SentryContext::class,
        ]);

        // Register middleware aliases
        $middleware->alias([
            'debug.only' => \App\Http\Middleware\DebugOnly::class,
            'internal-only' => \App\Http\Middleware\InternalOnly::class,
            'track-api-token' => \App\Http\Middleware\TrackApiTokenUsage::class,
            'two_factor' => \App\Http\Middleware\EnsureTwoFactorChallenged::class,
        ]);

        // Exclude API endpoints from CSRF protection
        $middleware->validateCsrfTokens(except: [
            '/api/analytics/session',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->reportable(function (\Throwable $e): void {
            if (app()->bound('sentry')) {
                app('sentry')->captureException($e);
            }
        });

        // Return JSON for API routes and requests that expect JSON
        $exceptions->shouldRenderJsonWhen(function ($request) {
            return $request->is('api/*') || $request->expectsJson();
        });

        // Consistent JSON error responses
        $exceptions->render(function (ValidationException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => 'validation_error',
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                ], 422);
            }
        });

        $exceptions->render(function (AuthenticationException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => 'unauthenticated',
                    'message' => 'Authentication is required to access this resource.',
                ], 401);
            }
        });

        $exceptions->render(function (AuthorizationException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => 'forbidden',
                    'message' => $e->getMessage() ?: 'You are not authorized to perform this action.',
                ], 403);
            }
        });

        $exceptions->render(function (ModelNotFoundException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => 'not_found',
                    'message' => 'The requested resource was not found.',
                ], 404);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => 'not_found',
                    'message' => 'The requested endpoint was not found.',
                ], 404);
            }
        });

        $exceptions->render(function (ThrottleRequestsException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => 'too_many_requests',
                    'message' => 'Rate limit exceeded. Please wait before trying again.',
                    'retry_after' => $e->getHeaders()['Retry-After'] ?? null,
                ], 429);
            }
        });
    })->create();
