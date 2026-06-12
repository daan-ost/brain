<?php

namespace App\Services\AI;

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSession;
use Carbon\Carbon;

class AnalyticsAggregatorService
{
    private const MAX_DAYS = 30;

    /**
     * Get aggregated analytics summary for a period.
     */
    public function getSummary(Carbon $from, Carbon $to): array
    {
        $this->validateDateRange($from, $to);

        return [
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'sessions' => $this->getSessionMetrics($from, $to),
            'pages' => $this->getPageMetrics($from, $to),
            'errors' => $this->getErrorMetrics($from, $to),
            'performance' => $this->getPerformanceMetrics($from, $to),
        ];
    }

    /**
     * Get user diagnostics for debugging.
     */
    public function getUserDiagnostics(int $userId, Carbon $from, Carbon $to): array
    {
        $this->validateDateRange($from, $to);

        return [
            'user_id' => $userId,
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'recent_sessions' => $this->getUserRecentSessions($userId, $from, $to),
            'errors' => $this->getUserErrors($userId, $from, $to),
            'rage_clicks' => $this->getUserRageClicks($userId, $from, $to),
            'page_flow' => $this->getUserPageFlow($userId, $from, $to),
        ];
    }

    /**
     * Validate date range is within allowed limits.
     */
    private function validateDateRange(Carbon $from, Carbon $to): void
    {
        if ($from->diffInDays($to) > self::MAX_DAYS) {
            throw new \InvalidArgumentException('Date range cannot exceed '.self::MAX_DAYS.' days');
        }

        if ($from->gt($to)) {
            throw new \InvalidArgumentException('From date must be before to date');
        }
    }

    /**
     * Get session-related metrics.
     */
    private function getSessionMetrics(Carbon $from, Carbon $to): array
    {
        $sessions = AnalyticsSession::query()
            ->whereBetween('started_at', [$from, $to])
            ->selectRaw('
                COUNT(*) as total,
                COUNT(DISTINCT COALESCE(user_id, guest_sid)) as unique_users,
                AVG(TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, last_activity_at))) as avg_duration,
                AVG(scroll_depth) * 100 as avg_scroll_depth,
                AVG(total_events) as actions_per_session,
                SUM(rage_clicks) as rage_clicks,
                SUM(CASE WHEN total_pages_viewed <= 1 AND TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, last_activity_at)) < 30 THEN 1 ELSE 0 END) as bounces
            ')
            ->first();

        $totalSessions = $sessions->total ?: 1; // Prevent division by zero

        // Get error rate from events
        $errorCount = AnalyticsEvent::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('event', 'error')
            ->count();

        $totalEvents = AnalyticsEvent::query()
            ->whereBetween('created_at', [$from, $to])
            ->count();

        return [
            'total' => (int) $sessions->total,
            'unique_users' => (int) $sessions->unique_users,
            'avg_duration' => round((float) $sessions->avg_duration, 1),
            'avg_scroll_depth' => round((float) $sessions->avg_scroll_depth, 0),
            'actions_per_session' => round((float) $sessions->actions_per_session, 1),
            'rage_clicks' => (int) $sessions->rage_clicks,
            'rage_click_rate' => round($sessions->rage_clicks / $totalSessions, 3),
            'bounce_rate' => round($sessions->bounces / $totalSessions, 2),
            'error_rate' => $totalEvents > 0 ? round($errorCount / $totalEvents, 2) : 0,
        ];
    }

    /**
     * Get page-related metrics.
     */
    private function getPageMetrics(Carbon $from, Carbon $to): array
    {
        // Get top pages from page_view events
        $topPages = AnalyticsEvent::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('event', 'page_view')
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.url')) as url, COUNT(*) as views")
            ->groupBy('url')
            ->orderByDesc('views')
            ->limit(10)
            ->get();

        // Calculate exit rates in a single query to avoid N+1
        $urls = $topPages->pluck('url')->filter()->values()->toArray();
        $exitMap = $this->calculateExitRatesBatch($urls, $from, $to, $topPages);

        return [
            'top_pages' => $topPages->map(function ($page) use ($exitMap) {
                return [
                    'url' => $page->url,
                    'views' => (int) $page->views,
                    'exit_rate' => $exitMap[$page->url] ?? 0,
                ];
            })->values()->toArray(),
            'exit_map' => $exitMap,
        ];
    }

    /**
     * Calculate exit rates for multiple URLs in a single query.
     */
    private function calculateExitRatesBatch(array $urls, Carbon $from, Carbon $to, $topPages): array
    {
        if (empty($urls)) {
            return [];
        }

        // Build view counts map
        $viewsMap = [];
        foreach ($topPages as $page) {
            if ($page->url) {
                $viewsMap[$page->url] = (int) $page->views;
            }
        }

        // Get exit counts for all URLs in a single query
        $exitCounts = AnalyticsSession::query()
            ->whereBetween('started_at', [$from, $to])
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(last_actions_before_exit, '$[0].url')) IN (".implode(',', array_fill(0, count($urls), '?')).')', $urls)
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(last_actions_before_exit, '$[0].url')) as exit_url, COUNT(*) as exits")
            ->groupBy('exit_url')
            ->pluck('exits', 'exit_url')
            ->toArray();

        // Calculate exit rates
        $exitMap = [];
        foreach ($urls as $url) {
            $totalViews = $viewsMap[$url] ?? 0;
            $exits = $exitCounts[$url] ?? 0;
            $exitMap[$url] = $totalViews > 0 ? round($exits / $totalViews, 2) : 0;
        }

        return $exitMap;
    }

    /**
     * Get error-related metrics.
     */
    private function getErrorMetrics(Carbon $from, Carbon $to): array
    {
        $errors = AnalyticsEvent::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('event', 'error')
            ->selectRaw("
                COALESCE(error_code, JSON_UNQUOTE(JSON_EXTRACT(meta, '$.message')), 'Unknown error') as msg,
                COUNT(*) as count
            ")
            ->groupBy('msg')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        $totalErrors = $errors->sum('count');

        // Calculate API error rate
        $apiEvents = AnalyticsEvent::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('event', 'like', 'api_%')
            ->count();

        $apiErrors = AnalyticsEvent::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('event', 'like', 'api_%')
            ->where('success', false)
            ->count();

        return [
            'count' => $totalErrors,
            'top_messages' => $errors->map(fn ($e) => [
                'msg' => $e->msg,
                'count' => (int) $e->count,
            ])->toArray(),
            'api_error_rate' => $apiEvents > 0 ? round($apiErrors / $apiEvents, 2) : 0,
        ];
    }

    /**
     * Get performance-related metrics.
     */
    private function getPerformanceMetrics(Carbon $from, Carbon $to): array
    {
        // Get load time from page_view events with duration
        $performance = AnalyticsEvent::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('event', 'page_view')
            ->whereNotNull('duration_ms')
            ->selectRaw('
                AVG(duration_ms) / 1000 as avg_load_time,
                COUNT(CASE WHEN duration_ms > 3000 THEN 1 END) as slow_pages
            ')
            ->first();

        // First interaction from click/input events
        $firstInteraction = AnalyticsEvent::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('event', ['click', 'input', 'form_submit'])
            ->whereNotNull('duration_ms')
            ->selectRaw('AVG(duration_ms) / 1000 as avg_first_interaction')
            ->first();

        // Slow sessions (sessions with frustration or long duration)
        $slowSessions = AnalyticsSession::query()
            ->whereBetween('started_at', [$from, $to])
            ->where(function ($q) {
                $q->where('frustration_score', '>', 0.5)
                    ->orWhereRaw('TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, last_activity_at)) > 300');
            })
            ->count();

        return [
            'avg_load_time' => round((float) ($performance->avg_load_time ?? 0), 1),
            'avg_first_interaction' => round((float) ($firstInteraction->avg_first_interaction ?? 0), 1),
            'slow_sessions' => $slowSessions,
        ];
    }

    /**
     * Get recent sessions for a user.
     */
    private function getUserRecentSessions(int $userId, Carbon $from, Carbon $to): array
    {
        return AnalyticsSession::query()
            ->where('user_id', $userId)
            ->whereBetween('started_at', [$from, $to])
            ->orderByDesc('started_at')
            ->limit(3)
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'started_at' => $s->started_at->toIso8601String(),
                'duration_seconds' => $s->ended_at
                    ? $s->started_at->diffInSeconds($s->ended_at)
                    : $s->started_at->diffInSeconds($s->last_activity_at),
                'pages_viewed' => $s->total_pages_viewed,
                'scroll_depth' => round($s->scroll_depth * 100),
                'frustration_score' => (float) $s->frustration_score,
            ])
            ->toArray();
    }

    /**
     * Get errors for a user.
     */
    private function getUserErrors(int $userId, Carbon $from, Carbon $to): array
    {
        return AnalyticsEvent::query()
            ->where('user_id', $userId)
            ->whereBetween('created_at', [$from, $to])
            ->where('event', 'error')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($e) => [
                'timestamp' => $e->created_at->toIso8601String(),
                'message' => $e->error_code ?? ($e->meta['message'] ?? 'Unknown'),
                'url' => $e->meta['url'] ?? null,
            ])
            ->toArray();
    }

    /**
     * Get rage click incidents for a user.
     */
    private function getUserRageClicks(int $userId, Carbon $from, Carbon $to): array
    {
        return AnalyticsSession::query()
            ->where('user_id', $userId)
            ->whereBetween('started_at', [$from, $to])
            ->where('rage_clicks', '>', 0)
            ->orderByDesc('rage_clicks')
            ->limit(10)
            ->get()
            ->map(fn ($s) => [
                'session_id' => $s->id,
                'timestamp' => $s->started_at->toIso8601String(),
                'rage_clicks' => $s->rage_clicks,
                'frustration_score' => (float) $s->frustration_score,
            ])
            ->toArray();
    }

    /**
     * Get page flow for a user (last actions before exit per session).
     */
    private function getUserPageFlow(int $userId, Carbon $from, Carbon $to): array
    {
        return AnalyticsSession::query()
            ->where('user_id', $userId)
            ->whereBetween('started_at', [$from, $to])
            ->whereNotNull('last_actions_before_exit')
            ->orderByDesc('started_at')
            ->limit(5)
            ->get()
            ->map(fn ($s) => [
                'session_id' => $s->id,
                'started_at' => $s->started_at->toIso8601String(),
                'exit_actions' => $s->last_actions_before_exit ?? [],
                'inferred_intent' => $s->inferred_intent,
            ])
            ->toArray();
    }
}
