<?php

use App\Models\Announcement;
use App\Models\User;
use App\Models\UserAnnouncement;
use App\Services\AnnouncementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Clear announcement cache before each test
    Cache::forget('announcement.active');
});

function createActiveAnnouncement(array $attributes = []): Announcement
{
    return Announcement::factory()->create(array_merge([
        'title_json' => ['en' => 'Test Announcement', 'nl' => 'Test Aankondiging'],
        'body_json' => ['en' => '<p>Test body</p>', 'nl' => '<p>Test inhoud</p>'],
        'urgency' => 'info',
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addDay(),
        'active' => true,
    ], $attributes));
}

describe('getActiveAnnouncement', function () {

    it('returns currently visible announcement', function () {
        $announcement = createActiveAnnouncement();

        $result = AnnouncementService::getActiveAnnouncement();

        expect($result)->not->toBeNull()
            ->and($result->id)->toBe($announcement->id);
    });

    it('returns null when no announcements exist', function () {
        $result = AnnouncementService::getActiveAnnouncement();

        expect($result)->toBeNull();
    });

    it('returns null when announcement is inactive', function () {
        createActiveAnnouncement(['active' => false]);

        AnnouncementService::clearCache();
        $result = AnnouncementService::getActiveAnnouncement();

        expect($result)->toBeNull();
    });

    it('returns null when announcement is expired', function () {
        createActiveAnnouncement([
            'starts_at' => now()->subWeek(),
            'ends_at' => now()->subDay(),
        ]);

        AnnouncementService::clearCache();
        $result = AnnouncementService::getActiveAnnouncement();

        expect($result)->toBeNull();
    });

    it('returns null when announcement is upcoming', function () {
        createActiveAnnouncement([
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addWeek(),
        ]);

        AnnouncementService::clearCache();
        $result = AnnouncementService::getActiveAnnouncement();

        expect($result)->toBeNull();
    });

    it('returns newest announcement when multiple are active', function () {
        $older = createActiveAnnouncement();
        $newer = createActiveAnnouncement();

        AnnouncementService::clearCache();
        $result = AnnouncementService::getActiveAnnouncement();

        expect($result->id)->toBe($newer->id);
    });

    it('caches the result', function () {
        $announcement = createActiveAnnouncement();

        // First call - should query database
        $result1 = AnnouncementService::getActiveAnnouncement();

        // Delete announcement from database
        $announcement->delete();

        // Second call - should return cached result
        $result2 = AnnouncementService::getActiveAnnouncement();

        expect($result1->id)->toBe($result2->id);
    });

    it('returns fresh result after cache clear', function () {
        $announcement1 = createActiveAnnouncement();
        AnnouncementService::getActiveAnnouncement(); // Cache it

        $announcement1->delete();
        $announcement2 = createActiveAnnouncement();

        AnnouncementService::clearCache();
        $result = AnnouncementService::getActiveAnnouncement();

        expect($result->id)->toBe($announcement2->id);
    });
});

describe('getAnnouncementToShow', function () {

    it('returns announcement for user who has not seen it', function () {
        $user = User::factory()->create();
        $announcement = createActiveAnnouncement();

        $result = AnnouncementService::getAnnouncementToShow($user);

        expect($result)->not->toBeNull()
            ->and($result->id)->toBe($announcement->id);
    });

    it('returns null for user who has already seen it', function () {
        $user = User::factory()->create();
        $announcement = createActiveAnnouncement();

        UserAnnouncement::create([
            'user_id' => $user->id,
            'announcement_id' => $announcement->id,
            'seen_at' => now(),
        ]);

        $result = AnnouncementService::getAnnouncementToShow($user);

        expect($result)->toBeNull();
    });

    it('returns announcement for guest (null user)', function () {
        $announcement = createActiveAnnouncement();

        $result = AnnouncementService::getAnnouncementToShow(null);

        expect($result)->not->toBeNull()
            ->and($result->id)->toBe($announcement->id);
    });

    it('returns null when no active announcement exists', function () {
        $user = User::factory()->create();

        $result = AnnouncementService::getAnnouncementToShow($user);

        expect($result)->toBeNull();
    });
});

describe('hasUserSeenAnnouncement', function () {

    it('returns false when user has not seen announcement', function () {
        $user = User::factory()->create();
        $announcement = createActiveAnnouncement();

        $result = AnnouncementService::hasUserSeenAnnouncement($user, $announcement);

        expect($result)->toBeFalse();
    });

    it('returns true when user has seen announcement', function () {
        $user = User::factory()->create();
        $announcement = createActiveAnnouncement();

        UserAnnouncement::create([
            'user_id' => $user->id,
            'announcement_id' => $announcement->id,
            'seen_at' => now(),
        ]);

        $result = AnnouncementService::hasUserSeenAnnouncement($user, $announcement);

        expect($result)->toBeTrue();
    });
});

describe('markAsSeenForUser', function () {

    it('creates user announcement record', function () {
        $user = User::factory()->create();
        $announcement = createActiveAnnouncement();

        AnnouncementService::markAsSeenForUser($user, $announcement);

        expect(UserAnnouncement::where('user_id', $user->id)
            ->where('announcement_id', $announcement->id)
            ->exists())->toBeTrue();
    });

    it('increments total views', function () {
        $user = User::factory()->create();
        $announcement = createActiveAnnouncement(['total_views' => 0]);

        AnnouncementService::markAsSeenForUser($user, $announcement);

        $announcement->refresh();
        expect($announcement->total_views)->toBe(1);
    });

    it('does not create duplicate records', function () {
        $user = User::factory()->create();
        $announcement = createActiveAnnouncement();

        AnnouncementService::markAsSeenForUser($user, $announcement);
        AnnouncementService::markAsSeenForUser($user, $announcement);

        $count = UserAnnouncement::where('user_id', $user->id)
            ->where('announcement_id', $announcement->id)
            ->count();

        expect($count)->toBe(1);
    });
});

describe('markAsSeenForGuest', function () {

    it('returns cookie with correct name', function () {
        $announcement = createActiveAnnouncement();

        $cookie = AnnouncementService::markAsSeenForGuest($announcement);

        expect($cookie->getName())->toBe("announcement_seen_{$announcement->id}");
    });

    it('returns cookie with value 1', function () {
        $announcement = createActiveAnnouncement();

        $cookie = AnnouncementService::markAsSeenForGuest($announcement);

        expect($cookie->getValue())->toBe('1');
    });

    it('increments total views', function () {
        $announcement = createActiveAnnouncement(['total_views' => 0]);

        AnnouncementService::markAsSeenForGuest($announcement);

        $announcement->refresh();
        expect($announcement->total_views)->toBe(1);
    });
});

describe('shouldShowOnCurrentRoute', function () {

    it('returns false for checkout routes', function () {
        // Create a fresh request with the desired path
        $request = Request::create('/checkout/step1', 'GET');
        app()->instance('request', $request);

        $result = AnnouncementService::shouldShowOnCurrentRoute();

        expect($result)->toBeFalse();
    });

    it('returns false for payment routes', function () {
        $request = Request::create('/payment/confirm', 'GET');
        app()->instance('request', $request);

        $result = AnnouncementService::shouldShowOnCurrentRoute();

        expect($result)->toBeFalse();
    });

    it('returns false for api routes', function () {
        $request = Request::create('/api/v1/convert', 'GET');
        app()->instance('request', $request);

        $result = AnnouncementService::shouldShowOnCurrentRoute();

        expect($result)->toBeFalse();
    });

    it('returns false for admin routes', function () {
        $request = Request::create('/admin/users', 'GET');
        app()->instance('request', $request);

        $result = AnnouncementService::shouldShowOnCurrentRoute();

        expect($result)->toBeFalse();
    });

    it('returns true for regular routes', function () {
        $request = Request::create('/doc-to-pdf', 'GET');
        app()->instance('request', $request);

        $result = AnnouncementService::shouldShowOnCurrentRoute();

        expect($result)->toBeTrue();
    });

    it('returns true for homepage', function () {
        $request = Request::create('/', 'GET');
        app()->instance('request', $request);

        $result = AnnouncementService::shouldShowOnCurrentRoute();

        expect($result)->toBeTrue();
    });

    it('returns true for profile routes', function () {
        $request = Request::create('/profile/account', 'GET');
        app()->instance('request', $request);

        $result = AnnouncementService::shouldShowOnCurrentRoute();

        expect($result)->toBeTrue();
    });
});

describe('clearCache', function () {

    it('removes announcement from cache', function () {
        $announcement = createActiveAnnouncement();

        // Populate cache
        AnnouncementService::getActiveAnnouncement();
        expect(Cache::has('announcement.active'))->toBeTrue();

        // Clear cache
        AnnouncementService::clearCache();

        expect(Cache::has('announcement.active'))->toBeFalse();
    });
});

describe('syncGuestToUser', function () {

    it('creates user announcement from guest cookie', function () {
        $user = User::factory()->create();
        $announcement = createActiveAnnouncement();

        // Simulate guest cookie
        request()->cookies->set("announcement_seen_{$announcement->id}", '1');

        AnnouncementService::syncGuestToUser($user);

        expect(UserAnnouncement::where('user_id', $user->id)
            ->where('announcement_id', $announcement->id)
            ->exists())->toBeTrue();
    });

    it('does not create duplicate when user already has record', function () {
        $user = User::factory()->create();
        $announcement = createActiveAnnouncement();

        // User already has record
        UserAnnouncement::create([
            'user_id' => $user->id,
            'announcement_id' => $announcement->id,
            'seen_at' => now()->subDay(),
        ]);

        // Simulate guest cookie
        request()->cookies->set("announcement_seen_{$announcement->id}", '1');

        AnnouncementService::syncGuestToUser($user);

        $count = UserAnnouncement::where('user_id', $user->id)
            ->where('announcement_id', $announcement->id)
            ->count();

        expect($count)->toBe(1);
    });

    it('handles missing cookies gracefully', function () {
        $user = User::factory()->create();
        createActiveAnnouncement();

        // No cookies set
        AnnouncementService::syncGuestToUser($user);

        expect(UserAnnouncement::where('user_id', $user->id)->count())->toBe(0);
    });
});
