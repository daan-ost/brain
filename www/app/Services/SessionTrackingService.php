<?php

namespace App\Services;

use App\Models\AnalyticsSession;
use Illuminate\Support\Str;

class SessionTrackingService
{
    /**
     * Get or create analytics session for current visitor
     */
    public static function getOrCreateSession(): ?AnalyticsSession
    {
        try {
            $sessionId = session('analytics_session_id');

            if ($sessionId) {
                $session = AnalyticsSession::find($sessionId);
                if ($session && ! $session->ended_at) {
                    $session->update(['last_activity_at' => now()]);

                    return $session;
                }
            }

            // Create new session
            // Use auth()->user()?->id instead of auth()->id() to verify user exists in database
            // This handles edge case where session has auth data for a deleted user
            $session = AnalyticsSession::create([
                'id' => Str::uuid()->toString(),
                'user_id' => auth()->user()?->id,
                'guest_sid' => session('guest_sid'),
                'device_type' => static::detectDeviceType(),
                'user_agent' => static::sanitizeUserAgent(request()->userAgent()),
                'started_at' => now(),
                'last_activity_at' => now(),
            ]);

            session(['analytics_session_id' => $session->id]);

            return $session;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Sanitize user agent string to prevent MySQL charset crashes
     * (e.g. bots sending invalid UTF-8 like serialized Joomla sessions with \xC2 bytes)
     */
    public static function sanitizeUserAgent(?string $userAgent): ?string
    {
        if ($userAgent === null) {
            return null;
        }

        $userAgent = str_replace("\0", '', $userAgent);
        $userAgent = mb_convert_encoding($userAgent, 'UTF-8', 'UTF-8');
        $userAgent = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $userAgent);

        return mb_substr($userAgent, 0, 500);
    }

    /**
     * Get current session ID without creating one
     */
    public static function getCurrentSessionId(): ?string
    {
        return session('analytics_session_id');
    }

    /**
     * Update session with client-side tracking data
     */
    public static function updateFromClient(string $sessionId, array $data): void
    {
        $session = AnalyticsSession::find($sessionId);
        if (! $session) {
            return;
        }

        $updates = ['last_activity_at' => now()];

        // Update session_group_id if provided (for multi-tab grouping)
        if (isset($data['session_group_id'])) {
            $updates['session_group_id'] = $data['session_group_id'];
        }

        if (isset($data['rage_clicks'])) {
            $updates['rage_clicks'] = max($session->rage_clicks, $data['rage_clicks']);
        }

        if (isset($data['rapid_click_count'])) {
            $updates['rapid_click_count'] = $session->rapid_click_count + $data['rapid_click_count'];
        }

        if (isset($data['form_abandonment']) && $data['form_abandonment']) {
            $updates['form_abandonment'] = true;
        }

        if (isset($data['scroll_depth'])) {
            $updates['scroll_depth'] = max($session->scroll_depth ?? 0, $data['scroll_depth']);
        }

        if (isset($data['actions']) && is_array($data['actions'])) {
            $existing = $session->session_actions ?? [];
            $maxActions = config('analytics.max_session_actions', 50);

            // Only append if under limit (max 50 items total)
            if (count($existing) < $maxActions) {
                $remaining = $maxActions - count($existing);
                $newActions = array_slice($data['actions'], 0, $remaining);
                $updates['session_actions'] = array_merge($existing, $newActions);
            }
            // Ignore extra events once limit reached
        }

        if (isset($data['exit_actions'])) {
            $updates['last_actions_before_exit'] = $data['exit_actions'];
            $updates['ended_at'] = now();
        }

        $session->update($updates);
    }

    /**
     * Detect device type from user agent
     */
    private static function detectDeviceType(): string
    {
        $agent = request()->userAgent() ?? '';

        if (preg_match('/bot|crawl|spider|slurp/i', $agent)) {
            return 'bot';
        }
        if (preg_match('/mobile|android|iphone/i', $agent)) {
            return 'mobile';
        }
        if (preg_match('/tablet|ipad/i', $agent)) {
            return 'tablet';
        }

        return 'desktop';
    }

    /**
     * End session (called on logout or explicit end)
     */
    public static function endSession(): void
    {
        $sessionId = session('analytics_session_id');

        if ($sessionId) {
            AnalyticsSession::where('id', $sessionId)
                ->whereNull('ended_at')
                ->update(['ended_at' => now()]);

            session()->forget('analytics_session_id');
        }
    }
}
