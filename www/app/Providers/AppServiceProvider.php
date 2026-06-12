<?php

namespace App\Providers;

use App\Services\AnnouncementService;
use App\Services\OrganizationContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Event as SchedulingEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Stripe\Stripe;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(OrganizationContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Fallback no-op macro wanneer Sentry niet geconfigureerd is (geen DSN)
        if (! SchedulingEvent::hasMacro('sentryMonitor')) {
            SchedulingEvent::macro('sentryMonitor', fn (?string $monitorSlug = null) => $this);
        }

        // Register view components
        $this->loadViewComponentsAs('', [
            'landing-layout' => \App\View\Components\LandingLayout::class,
        ]);

        // Register announcement View Composer for main layouts
        $this->registerAnnouncementComposer();

        // Register event listeners
        $this->registerEventListeners();

        // Register rate limiters for share downloads
        $this->registerShareRateLimiters();

        // Register rate limiters for analytics
        $this->registerAnalyticsRateLimiters();

        // Register rate limiters for API v2
        $this->registerApiV2RateLimiters();

        // Warn if APP_DEBUG is enabled in production
        if ($this->app->isProduction() && config('app.debug')) {
            Log::warning('APP_DEBUG is enabled in production! This exposes sensitive information. Set APP_DEBUG=false in your .env file.');
        }

        // Bootstrap Stripe SDK once (services initialize per-request if key is set)
        if ($key = config('services.stripe.secret_key')) {
            Stripe::setApiKey($key);
            Stripe::setApiVersion(config('services.stripe.api_version', '2025-04-30.basil'));
            Stripe::setAppInfo('basewebsite', '1.0', config('app.url'));
        }
    }

    /**
     * Register rate limiters for share link downloads
     * Per spec: 5 downloads/token/hour, 20 downloads/IP/day
     */
    private function registerShareRateLimiters(): void
    {
        // 5 downloads per share token per hour
        RateLimiter::for('share-token', function ($request) {
            $token = $request->route('shareToken');

            return Limit::perHour(5)->by($token ?: 'unknown');
        });

        // 20 downloads per IP per day
        RateLimiter::for('share-ip', function ($request) {
            return Limit::perDay(20)->by($request->ip());
        });
    }

    /**
     * Register rate limiters for analytics API
     * Per spec: 20 requests per minute per session_id
     */
    private function registerAnalyticsRateLimiters(): void
    {
        RateLimiter::for('analytics', function ($request) {
            $sessionId = $request->input('session_id', 'unknown');
            $limit = config('analytics.rate_limit_per_minute', 20);

            return Limit::perMinute($limit)->by($sessionId);
        });
    }

    /**
     * Register rate limiters for API v2
     * 100 requests per minute per user
     */
    private function registerApiV2RateLimiters(): void
    {
        RateLimiter::for('api-v2', function ($request) {
            $user = $request->user();

            return Limit::perMinute(100)
                ->by($user?->id ?: $request->ip())
                ->response(function ($request, $headers) {
                    return response()->json([
                        'error' => 'Rate limit exceeded',
                        'message' => 'Too many requests. Please wait before trying again.',
                        'retry_after' => $headers['Retry-After'] ?? 60,
                    ], 429, $headers);
                });
        });
    }

    /**
     * Register event listeners
     */
    private function registerEventListeners(): void
    {
        // Auto-enroll users into organizations when they verify their email
        // See: /docs/todo_0_autoenrollment.md
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Auth\Events\Verified::class,
            \App\Listeners\AutoEnrollUserInOrganization::class
        );

        // Sync guest announcement cookies to user record on login
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Auth\Events\Login::class,
            function ($event) {
                if ($event->user instanceof \App\Models\User) {
                    AnnouncementService::syncGuestToUser($event->user);
                }
            }
        );

        // Track last login timestamp on users
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Auth\Events\Login::class,
            \App\Listeners\UpdateUserLastLoginAt::class
        );
    }

    /**
     * Register announcement View Composer for main layouts
     */
    private function registerAnnouncementComposer(): void
    {
        // Share announcement data with main layouts
        View::composer([
            'layouts.app',
            'layouts.landing',
            'layouts.guest',
            'layouts.profile',
            'layouts.homepage-standalone',
            'components.landing-layout',
        ], function ($view) {
            // Skip if on excluded routes
            if (! AnnouncementService::shouldShowOnCurrentRoute()) {
                $view->with('activeAnnouncement', null);

                return;
            }

            $user = auth()->user();
            $announcement = AnnouncementService::getAnnouncementToShow($user);

            if ($announcement) {
                $view->with('activeAnnouncement', $announcement->toFrontendArray());
            } else {
                $view->with('activeAnnouncement', null);
            }
        });
    }
}
