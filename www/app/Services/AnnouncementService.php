<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\User;
use App\Models\UserAnnouncement;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;

class AnnouncementService
{
    /**
     * Cache TTL in seconds (5 minutes)
     */
    private const CACHE_TTL = 300;

    /**
     * Cookie duration in minutes (90 days)
     */
    private const COOKIE_DURATION = 129600;

    /**
     * Get the currently active announcement (newest one)
     */
    public static function getActiveAnnouncement(): ?Announcement
    {
        return Cache::remember('announcement.active', self::CACHE_TTL, function () {
            return Announcement::currentlyVisible()
                ->orderBy('id', 'desc')
                ->first();
        });
    }

    /**
     * Get announcement to show for the current request
     * Returns null if no announcement should be shown
     */
    public static function getAnnouncementToShow(?User $user): ?Announcement
    {
        $announcement = self::getActiveAnnouncement();

        if (! $announcement) {
            return null;
        }

        // Check if user/guest has already seen it
        if ($user) {
            if (self::hasUserSeenAnnouncement($user, $announcement)) {
                return null;
            }
        } else {
            if (self::hasGuestSeenAnnouncement($announcement->id)) {
                return null;
            }
        }

        return $announcement;
    }

    /**
     * Check if a logged-in user has seen the announcement
     */
    public static function hasUserSeenAnnouncement(User $user, Announcement $announcement): bool
    {
        return UserAnnouncement::where('user_id', $user->id)
            ->where('announcement_id', $announcement->id)
            ->exists();
    }

    /**
     * Check if a guest has seen the announcement (via cookie)
     */
    public static function hasGuestSeenAnnouncement(int $announcementId): bool
    {
        $cookieName = self::getCookieName($announcementId);

        return request()->cookie($cookieName) === '1';
    }

    /**
     * Mark announcement as seen for a logged-in user
     */
    public static function markAsSeenForUser(User $user, Announcement $announcement): void
    {
        UserAnnouncement::firstOrCreate([
            'user_id' => $user->id,
            'announcement_id' => $announcement->id,
        ], [
            'seen_at' => now(),
        ]);

        // Increment view counter
        $announcement->incrementViews();

        // Log analytics event
        AnalyticsService::log('announcement_shown', [
            'announcement_id' => $announcement->id,
        ]);
    }

    /**
     * Mark announcement as seen for a guest (returns cookie to be queued)
     */
    public static function markAsSeenForGuest(Announcement $announcement): \Symfony\Component\HttpFoundation\Cookie
    {
        // Increment view counter
        $announcement->incrementViews();

        // Log analytics event
        AnalyticsService::log('announcement_shown', [
            'announcement_id' => $announcement->id,
        ]);

        // Return cookie to be set
        $cookieName = self::getCookieName($announcement->id);

        return Cookie::make($cookieName, '1', self::COOKIE_DURATION, '/', null, false, false, false, 'Lax');
    }

    /**
     * Sync guest announcement cookies to user record on login
     */
    public static function syncGuestToUser(User $user): void
    {
        // Get all active/recent announcements
        $announcements = Announcement::where('ends_at', '>=', now()->subDays(90))->get();

        foreach ($announcements as $announcement) {
            $cookieName = self::getCookieName($announcement->id);

            if (request()->cookie($cookieName) === '1') {
                // Guest had seen this announcement, sync to user record
                UserAnnouncement::firstOrCreate([
                    'user_id' => $user->id,
                    'announcement_id' => $announcement->id,
                ], [
                    'seen_at' => now(),
                ]);

                // Note: We can't delete cookies from here, but they'll expire naturally
            }
        }
    }

    /**
     * Clear the announcement cache
     */
    public static function clearCache(): void
    {
        Cache::forget('announcement.active');
    }

    /**
     * Get cookie name for an announcement
     */
    private static function getCookieName(int $announcementId): string
    {
        return "announcement_seen_{$announcementId}";
    }

    /**
     * Check if current route should show announcements
     */
    public static function shouldShowOnCurrentRoute(): bool
    {
        $currentPath = request()->path();

        $excludedPatterns = [
            'checkout',
            'payment',
            'redirect',
            'api/',
            'admin/',
        ];

        foreach ($excludedPatterns as $pattern) {
            if (str_starts_with($currentPath, $pattern)) {
                return false;
            }
        }

        return true;
    }
}
