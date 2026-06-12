<?php

namespace App\Services;

use App\Models\AnalyticsEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AnalyticsService
{
    public static function log(string $event, array $meta = []): void
    {
        $user = Auth::user();
        $guestSid = session('guest_sid');

        // Add site (locale) to metadata - always first
        $meta = array_merge([
            'site' => app()->getLocale(),
        ], $meta);

        // Sanitize user_agent to prevent MySQL charset crashes from invalid UTF-8 bot strings
        if (isset($meta['user_agent'])) {
            $meta['user_agent'] = SessionTrackingService::sanitizeUserAgent($meta['user_agent']);
        }

        // Mask sensitive data (passwords, API keys, etc.)
        $meta = static::maskSensitiveData($meta);

        // Add country data to metadata
        $meta['country'] = static::resolveCountry();
        $meta['country_source'] = $user ? 'user_data' : 'ip_detection';

        // Normalize referrer - always normalize if provided, or get from request header for web requests
        if (isset($meta['referrer'])) {
            // Normalize explicitly provided referrer
            $meta['referrer'] = static::normalizeReferrer($meta['referrer']);
        } elseif (! app()->runningInConsole()) {
            // Get from request header for web requests
            $meta['referrer'] = static::normalizeReferrer(request()->header('referer'));
        }

        AnalyticsEvent::create([
            'user_id' => $user?->id,
            'session_id' => SessionTrackingService::getCurrentSessionId(),
            'guest_sid' => $guestSid,
            'event' => $event,
            'meta' => $meta,
            'created_at' => now(),
        ]);
    }

    /**
     * Mask sensitive data in metadata (passwords, API keys, etc.)
     */
    private static function maskSensitiveData(array $data): array
    {
        $sensitiveKeys = ['password', 'api_key', 'secret', 'token', 'private_key'];

        foreach ($data as $key => &$value) {
            // Recursively mask nested arrays
            if (is_array($value)) {
                $value = static::maskSensitiveData($value);
            }
            // Mask sensitive keys
            elseif (in_array(strtolower($key), $sensitiveKeys) && ! empty($value)) {
                $value = '***';
            }
        }

        return $data;
    }

    public static function getOrCreateGuestSid(): string
    {
        $guestSid = session('guest_sid');

        if (! $guestSid) {
            $guestSid = Str::uuid()->toString();
            session(['guest_sid' => $guestSid]);
        }

        return $guestSid;
    }

    public static function transferGuestDataToUser(int $userId): void
    {
        $guestSid = session('guest_sid');

        if (! $guestSid) {
            return;
        }

        // Transfer analytics events
        AnalyticsEvent::where('guest_sid', $guestSid)
            ->whereNull('user_id')
            ->update(['user_id' => $userId]);
    }

    /**
     * Get event counts grouped by type for a date range
     */
    public static function getEventCounts(Carbon $start, Carbon $end): array
    {
        return AnalyticsEvent::whereBetween('created_at', [$start, $end])
            ->select('event', DB::raw('count(*) as count'))
            ->groupBy('event')
            ->orderByDesc('count')
            ->pluck('count', 'event')
            ->toArray();
    }

    /**
     * Get user journey metrics
     */
    public static function getUserJourney(Carbon $start, Carbon $end): array
    {
        return [
            'landing_views' => static::getEventCount('landing_page_view', $start, $end),
            'file_uploads' => static::getEventCount('file_upload', $start, $end),
            'conversions_started' => static::getEventCount('conversion_started', $start, $end),
            'conversions_completed' => static::getEventCount('conversion_completed', $start, $end),
            'user_registrations' => static::getEventCount('user_registered', $start, $end),
        ];
    }

    /**
     * Get count of specific event type in date range
     */
    public static function getEventCount(string $event, Carbon $start, Carbon $end): int
    {
        return AnalyticsEvent::where('event', $event)
            ->whereBetween('created_at', [$start, $end])
            ->count();
    }

    /**
     * Get unique users (guests + authenticated) for date range
     */
    public static function getUniqueUsers(Carbon $start, Carbon $end): array
    {
        $guests = AnalyticsEvent::whereNull('user_id')
            ->whereBetween('created_at', [$start, $end])
            ->distinct('guest_sid')
            ->count();

        $authenticated = AnalyticsEvent::whereNotNull('user_id')
            ->whereBetween('created_at', [$start, $end])
            ->distinct('user_id')
            ->count();

        return [
            'guests' => $guests,
            'authenticated' => $authenticated,
            'total' => $guests + $authenticated,
        ];
    }

    /**
     * Get conversion funnel drop-off analysis
     */
    public static function getConversionFunnel(Carbon $start, Carbon $end): array
    {
        $journey = static::getUserJourney($start, $end);

        return [
            'landing_to_upload' => $journey['landing_views'] > 0
                ? round(($journey['file_uploads'] / $journey['landing_views']) * 100, 1)
                : 0,
            'upload_to_conversion' => $journey['file_uploads'] > 0
                ? round(($journey['conversions_started'] / $journey['file_uploads']) * 100, 1)
                : 0,
            'conversion_success_rate' => $journey['conversions_started'] > 0
                ? round(($journey['conversions_completed'] / $journey['conversions_started']) * 100, 1)
                : 0,
            'landing_to_registration' => $journey['landing_views'] > 0
                ? round(($journey['user_registrations'] / $journey['landing_views']) * 100, 1)
                : 0,
        ];
    }

    /**
     * Get popular landing pages with view counts
     */
    public static function getPopularPages(Carbon $start, Carbon $end, int $limit = 10): array
    {
        return AnalyticsEvent::where('event', 'landing_page_view')
            ->whereBetween('created_at', [$start, $end])
            ->whereNotNull(DB::raw('JSON_EXTRACT(meta, "$.page")'))
            ->select(
                DB::raw('JSON_UNQUOTE(JSON_EXTRACT(meta, "$.page")) as page'),
                DB::raw('count(*) as views')
            )
            ->groupBy('page')
            ->orderByDesc('views')
            ->limit($limit)
            ->pluck('views', 'page')
            ->toArray();
    }

    /**
     * Get daily event timeline for charting
     */
    public static function getDailyEventTimeline(Carbon $start, Carbon $end, ?string $eventFilter = null): array
    {
        $query = AnalyticsEvent::whereBetween('created_at', [$start, $end]);

        if ($eventFilter) {
            $query->where('event', 'like', '%'.$eventFilter.'%');
        }

        return $query
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('count', 'date')
            ->toArray();
    }

    /**
     * Get email delivery metrics
     */
    public static function getEmailMetrics(Carbon $start, Carbon $end): array
    {
        $sent = static::getEventCount('email_sent', $start, $end);
        $delivered = static::getEventCount('email_delivered', $start, $end);
        $bounced = static::getEventCount('email_bounced', $start, $end);
        $opened = static::getEventCount('email_opened', $start, $end);

        return [
            'sent' => $sent,
            'delivered' => $delivered,
            'bounced' => $bounced,
            'opened' => $opened,
            'delivery_rate' => $sent > 0 ? round(($delivered / $sent) * 100, 1) : 0,
            'open_rate' => $delivered > 0 ? round(($opened / $delivered) * 100, 1) : 0,
            'bounce_rate' => $sent > 0 ? round(($bounced / $sent) * 100, 1) : 0,
        ];
    }

    /**
     * Get popular countries with event counts
     */
    public static function getCountryBreakdown(Carbon $start, Carbon $end, ?string $eventFilter = null): array
    {
        $query = AnalyticsEvent::whereBetween('created_at', [$start, $end]);

        if ($eventFilter) {
            $query->where('event', $eventFilter);
        }

        return $query
            ->whereNotNull(DB::raw('JSON_EXTRACT(meta, "$.country")'))
            ->select(
                DB::raw('JSON_UNQUOTE(JSON_EXTRACT(meta, "$.country")) as country'),
                DB::raw('count(*) as total_events'),
                DB::raw('count(distinct case when user_id is not null then user_id end) as unique_users'),
                DB::raw('count(distinct case when user_id is null then guest_sid end) as unique_guests')
            )
            ->groupBy('country')
            ->orderByDesc('total_events')
            ->limit(15)
            ->get()
            ->map(function ($item) {
                return [
                    'country' => $item->country,
                    'total_events' => $item->total_events,
                    'unique_users' => $item->unique_users,
                    'unique_guests' => $item->unique_guests,
                    'total_visitors' => $item->unique_users + $item->unique_guests,
                ];
            })
            ->toArray();
    }

    /**
     * Get conversion funnel by country
     */
    public static function getConversionFunnelByCountry(Carbon $start, Carbon $end, string $country): array
    {
        $events = [
            'landing_views' => static::getEventCountByCountry('landing_page_view', $country, $start, $end),
            'conversions_started' => static::getEventCountByCountry('conversion_started', $country, $start, $end),
            'conversions_completed' => static::getEventCountByCountry('conversion_completed', $country, $start, $end),
            'signups' => static::getEventCountByCountry('user_registered', $country, $start, $end),
        ];

        return [
            'events' => $events,
            'conversion_rate' => $events['landing_views'] > 0
                ? round(($events['conversions_completed'] / $events['landing_views']) * 100, 1)
                : 0,
            'signup_rate' => $events['landing_views'] > 0
                ? round(($events['signups'] / $events['landing_views']) * 100, 1)
                : 0,
        ];
    }

    /**
     * Get event count for specific country
     */
    private static function getEventCountByCountry(string $event, string $country, Carbon $start, Carbon $end): int
    {
        return AnalyticsEvent::where('event', $event)
            ->whereBetween('created_at', [$start, $end])
            ->where(DB::raw('JSON_EXTRACT(meta, "$.country")'), $country)
            ->count();
    }

    /**
     * Get country options for dropdown
     */
    public static function getCountryOptions(): array
    {
        // Start with a basic list of common countries
        $countries = [
            'US' => 'United States',
            'CA' => 'Canada',
            'GB' => 'United Kingdom',
            'AU' => 'Australia',
            'DE' => 'Germany',
            'FR' => 'France',
            'IT' => 'Italy',
            'ES' => 'Spain',
            'NL' => 'Netherlands',
            'BE' => 'Belgium',
            'CH' => 'Switzerland',
            'AT' => 'Austria',
            'SE' => 'Sweden',
            'NO' => 'Norway',
            'DK' => 'Denmark',
            'FI' => 'Finland',
            'PL' => 'Poland',
            'BR' => 'Brazil',
            'JP' => 'Japan',
            'IN' => 'India',
            'CN' => 'China',
            'RU' => 'Russia',
            'TR' => 'Turkey',
            'MX' => 'Mexico',
            'KR' => 'South Korea',
            'SG' => 'Singapore',
            'ZA' => 'South Africa',
        ];

        // Add countries that appear in analytics but aren't in the list
        $analyticsCountries = AnalyticsEvent::whereNotNull(DB::raw('JSON_EXTRACT(meta, "$.country")'))
            ->select(DB::raw('DISTINCT JSON_UNQUOTE(JSON_EXTRACT(meta, "$.country")) as country'))
            ->pluck('country')
            ->filter()
            ->toArray();

        foreach ($analyticsCountries as $country) {
            if (! isset($countries[$country])) {
                $countries[$country] = $country;
            }
        }

        asort($countries);

        return $countries;
    }

    /**
     * Resolve the best available country for current user/visitor
     */
    private static function resolveCountry(): ?string
    {
        $user = Auth::user();

        if ($user) {
            // For authenticated users: use their stored country data
            return $user->billing_country_code ?? $user->ipregistry_country_code;
        }

        try {
            // For guests: detect from IP in real-time
            $ipRegistryService = app(IPRegistryService::class);
            $countryInfo = $ipRegistryService->getCountryFromIP();

            return $countryInfo['code'] ?? null;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to resolve country for analytics', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Normalize referrer: strip domain if same as app, keep full URL for external
     */
    private static function normalizeReferrer(?string $referrer): ?string
    {
        if (empty($referrer)) {
            return null;
        }

        $appHost = parse_url(config('app.url'), PHP_URL_HOST);
        $referrerHost = parse_url($referrer, PHP_URL_HOST);

        // Same domain → only keep path
        if ($appHost && $referrerHost && $appHost === $referrerHost) {
            $path = parse_url($referrer, PHP_URL_PATH) ?? '/';
            $query = parse_url($referrer, PHP_URL_QUERY);

            return $query ? "{$path}?{$query}" : $path;
        }

        // External domain → keep full URL
        return $referrer;
    }
}
